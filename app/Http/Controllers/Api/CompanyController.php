<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\CompanyRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
class CompanyController extends Controller {
 const KINDS=['group','person','job','faq','about_faq','content'];
 private function canManage(Request $request, string $kind): bool {
  $permission = in_array($kind, ['job', 'faq'], true) ? 'careers.manage' : 'company.manage';
  return $request->user()->hasPermission($permission) || $request->user()->hasPermission('users.manage');
 }

 public function place(Request $request, int $id) {
  $placement = $request->validate([
   'group_id' => ['sometimes', 'nullable', 'integer'],
   'before_id' => ['present', 'nullable', 'integer'],
  ]);

  $changed = DB::transaction(function () use ($request, $id, $placement) {
   $record = CompanyRecord::findOrFail($id);
   abort_unless($this->canManage($request, $record->kind), 403);
   $team = $record->kind === 'person';
   abort_if(! $team && array_key_exists('group_id', $placement), 422, 'Only people can move between groups.');

   // Lock the groups before their members, so a destination cannot disappear during a move.
   $groups = $team ? CompanyRecord::where('kind', 'group')->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
   $records = CompanyRecord::where('kind', $record->kind)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
   $record = $records->get($id);
   abort_unless($record, 404);
   $group = array_key_exists('group_id', $placement) ? $placement['group_id'] : ($record->data['group_id'] ?? null);
   $destination = $group === null ? null : (int) $group;
   abort_if($team && $destination !== null && ! $groups->has($destination), 422, 'Choose an existing team group.');

   $ordered = $records->filter(fn ($item) => $item->id !== $id
    && (! $team || ($item->data['group_id'] ?? null) === $destination))
    ->sortBy(fn ($item) => $item->data['order'] ?? 0)->values();
   $position = $placement['before_id'] === null ? $ordered->count() : $ordered->search(fn ($item) => $item->id === (int) $placement['before_id']);
   abort_if($position === false, 422, 'The drop target moved. Refresh the list and try again.');
   $ordered->splice($position, 0, [$record]);

   return $ordered->map(function ($item, $index) use ($id, $team, $destination) {
    $data = $item->data;
    $data['order'] = $index;
    if ($team && $item->id === $id) {
     $data['group_id'] = $destination;
    }
    if ($data !== $item->data) {
     $item->update(['data' => $data]);
    }
    return $item;
   });
  });

  return response()->json(['data' => $changed]);
 }
 public function index(Request $r) {
  $admin=$r->is('api/admin/*');
  $records=CompanyRecord::all()->filter(fn($x)=>$admin ? $this->canManage($r, $x->kind) : (($x->data['published']??false) && ($x->kind!=='job' || empty($x->data['closing']) || $x->data['closing']>=now()->toDateString())))->values();
  return response()->json(['data'=>$records]);
 }
 public function save(Request $r, ?int $id=null) {
  $v=$r->validate(['kind'=>['required',Rule::in(self::KINDS)],'data'=>'required|array','data.name'=>'required|string|max:200','data.title'=>'nullable|string|max:200','data.bio'=>'nullable|string|max:12000','data.full_bio'=>'nullable|string|max:20000','data.sample_bio'=>'nullable|boolean','data.links.*.type'=>'nullable|in:linkedin,website,x,instagram','data.links.*.visible'=>'nullable|boolean','data.description'=>'nullable|string|max:20000','data.email'=>'nullable|email|max:200','data.phone'=>'nullable|string|max:80','data.group_id'=>'nullable|integer|exists:company_records,id','data.order'=>'nullable|integer|min:0|max:9999','data.published'=>'required|boolean','data.links'=>'nullable|array|max:10','data.links.*.label'=>'required|string|max:80','data.links.*.url'=>'required|url:http,https|max:1000','data.image'=>'nullable|string|max:500','data.focal'=>'nullable|integer|min:0|max:100','data.focal_x'=>'nullable|integer|min:0|max:100','data.zoom'=>'nullable|integer|min:100|max:250','data.location'=>'nullable|string|max:200','data.employment'=>'nullable|string|max:100','data.department'=>'nullable|string|max:100','data.arrangement'=>'nullable|in:Remote,Hybrid,On-site','data.closing'=>'nullable|date','data.intro'=>'nullable|string|max:5000','data.answer'=>'nullable|string|max:5000']);
  if(!empty($v['data']['group_id'])) abort_unless(CompanyRecord::findOrFail($v['data']['group_id'])->kind==='group',422,'Choose a team group.');
  if(!empty($v['data']['image'])) abort_unless(preg_match('#^/(team/|api/company-photo/)#',$v['data']['image']),422,'Upload a profile image.');
  abort_unless($this->canManage($r, $v['kind']), 403);
  $record = DB::transaction(function () use ($id, $v) {
   $record = $id ? CompanyRecord::lockForUpdate()->findOrFail($id) : new CompanyRecord;
   abort_unless(! $id || $record->kind === $v['kind'], 403);
   foreach (['order', 'group_id'] as $key) {
    if ($id && ! array_key_exists($key, $v['data']) && array_key_exists($key, $record->data)) {
     $v['data'][$key] = $record->data[$key];
    }
   }
   $record->fill($v)->save();
   return $record;
  });
  return response()->json(['data' => $record]);
 }
 public function destroy(Request $r, int $id) {
  $record=CompanyRecord::findOrFail($id);
  abort_unless($this->canManage($r, $record->kind), 403);
  DB::transaction(function()use($record){ if($record->kind==='group') foreach(CompanyRecord::where('kind','person')->get() as $p) if(($p->data['group_id']??null)===$record->id){$d=$p->data;$d['group_id']=null;$d['published']=false;$p->update(['data'=>$d]);} $record->delete(); });
  return response()->json(['message'=>'Removed.']);
 }
 public function upload(Request $r) {
  $r->validate(['image'=>'required|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=10000,max_height=10000']);
  $path=$r->file('image')->store('team','local'); return response()->json(['image'=>'/api/company-photo/'.basename($path)]);
 }
 public function photo(string $file) {
  abort_unless(preg_match('/^[a-zA-Z0-9]+\.(jpg|jpeg|png|webp)$/', $file), 404);
  $disk = Storage::disk('local');
  $path = 'team/'.$file;
  abort_unless($disk->exists($path), 404);
  return $disk->response($path);
 }
 public function apply(Request $r) {
  $v=$r->validate(['job_id'=>'nullable|integer|exists:company_records,id','name'=>'required|string|max:150','email'=>'required|email|max:200','phone'=>'nullable|string|max:80','message'=>'nullable|string|max:5000','resume'=>'required|file|mimes:pdf|max:5120','consent'=>'required|accepted']);
  if(!empty($v['job_id'])) {$j=CompanyRecord::findOrFail($v['job_id']);abort_unless($j->kind==='job'&&($j->data['published']??false)&&(empty($j->data['closing'])||$j->data['closing']>=now()->toDateString()),422,'This opening is closed.');}
  unset($v['consent']);$v['resume']=$r->file('resume')->store('resumes','local');$v['created_at']=now();$v['updated_at']=now(); DB::table('career_applications')->insert($v); return response()->json(['message'=>'Application received. Our team will review it.'],201);
 }
 public function applications(){return response()->json(['data'=>DB::table('career_applications')->orderByDesc('id')->get()->map(fn($a)=>collect($a)->except('resume'))]);}
 public function application(Request $r,int $id){$v=$r->validate(['status'=>'required|in:new,reviewing,interview,offered,hired,rejected','notes'=>'nullable|string|max:10000']);abort_unless(DB::table('career_applications')->where('id',$id)->exists(),404);DB::table('career_applications')->where('id',$id)->update($v);return response()->json(['message'=>'Updated.']);}
 public function resume(int $id){$a=DB::table('career_applications')->find($id);abort_unless($a,404);return Storage::disk('local')->download($a->resume,'application-'.$id.'.pdf');}
}
