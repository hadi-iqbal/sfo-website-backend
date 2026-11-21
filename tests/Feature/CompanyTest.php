<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\CompanyRecord;
use Laravel\Sanctum\Sanctum;
class CompanyTest extends TestCase {
 use RefreshDatabase;
 public function test_group_placement_and_crop_are_persisted_without_changing_publication(): void {
  Sanctum::actingAs(User::factory()->create(['is_super_admin'=>true,'is_active'=>true]), ['portal']);
  $first=CompanyRecord::create(['kind'=>'group','data'=>['name'=>'First team','published'=>true,'order'=>100]]);
  $second=CompanyRecord::create(['kind'=>'group','data'=>['name'=>'Second team','published'=>true,'order'=>200]]);
  $a=CompanyRecord::create(['kind'=>'person','data'=>['name'=>'Alice','published'=>true,'group_id'=>$first->id,'order'=>100]]);
  $b=CompanyRecord::create(['kind'=>'person','data'=>['name'=>'Ben','published'=>true,'group_id'=>$second->id,'order'=>100]]);
  $this->patchJson("/api/admin/company/{$a->id}/placement",['group_id'=>$second->id,'before_id'=>$b->id])
   ->assertOk()->assertJsonPath('data.0.id',$a->id)->assertJsonPath('data.1.id',$b->id);
  $this->assertSame($second->id,$a->fresh()->data['group_id']);
  $this->assertTrue($a->fresh()->data['published']);
  $this->patchJson("/api/admin/company/{$a->id}/placement",['group_id'=>$first->id,'before_id'=>$b->id])->assertUnprocessable();
  $this->assertSame($second->id,$a->fresh()->data['group_id']);
  $this->putJson("/api/admin/company/{$a->id}",['kind'=>'person','data'=>['name'=>'Alice','published'=>true,'focal_x'=>72,'focal'=>22,'zoom'=>155]])
   ->assertOk()->assertJsonPath('data.data.group_id',$second->id)->assertJsonPath('data.data.order',0)->assertJsonPath('data.data.zoom',155);
  $this->patchJson("/api/admin/company/{$b->id}/placement",['group_id'=>null,'before_id'=>null])->assertOk()->assertJsonPath('data.0.data.group_id',null);
  $this->patchJson("/api/admin/company/{$second->id}/placement",['before_id'=>$first->id])->assertOk()->assertJsonPath('data.0.id',$second->id);
  Sanctum::actingAs(User::factory()->create(['is_super_admin'=>false,'is_active'=>true]), ['portal']);
  $this->patchJson("/api/admin/company/{$a->id}/placement",['group_id'=>null,'before_id'=>null])->assertForbidden();
 }
 public function test_company_sections_and_profile_fields_persist():void {
  $this->seed([\Database\Seeders\CompanySeeder::class,\Database\Seeders\TeamProfileDemoSeeder::class,\Database\Seeders\AboutNarrativeSeeder::class]);
  $count=CompanyRecord::count();
  $this->seed(\Database\Seeders\AboutNarrativeSeeder::class);
  $this->assertSame($count,CompanyRecord::count());
  $this->assertSame(4,CompanyRecord::where('kind','content')->count());
  Sanctum::actingAs(User::factory()->create(['is_super_admin'=>true,'is_active'=>true]), ['portal']);
  $person=CompanyRecord::where('kind','person')->first();
  $data=$person->data;$data['bio']='Updated short biography';$data['full_bio']='Updated full biography';$data['links'][0]['visible']=false;
  $this->putJson('/api/admin/company/'.$person->id,['kind'=>'person','data'=>$data])->assertOk()->assertJsonPath('data.data.full_bio','Updated full biography')->assertJsonPath('data.data.links.0.visible',false);
  $this->assertSame('Updated short biography',$person->fresh()->data['bio']);
 }
 public function test_photo_upload_and_permissions():void {
  Storage::fake('local');
  Sanctum::actingAs(User::factory()->create(['is_super_admin'=>false,'is_active'=>true]), ['portal']);
  $this->getJson('/api/admin/company')->assertForbidden();
  Sanctum::actingAs(User::factory()->create(['is_super_admin'=>true,'is_active'=>true]), ['portal']);
  $this->postJson('/api/admin/company-image',['image'=>UploadedFile::fake()->create('bad.pdf',10,'application/pdf')])->assertUnprocessable();
  $file=UploadedFile::fake()->createWithContent('portrait.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1sAAAAASUVORK5CYII='));
  $url=$this->post('/api/admin/company-image',['image'=>$file],['Accept'=>'application/json'])->assertOk()->json('image');
  $this->get($url)->assertOk();
 }
 public function test_publishing_groups_and_validation():void {
  $this->postJson('/api/admin/company',[])->assertUnauthorized();
  Sanctum::actingAs(User::factory()->create(['is_super_admin'=>true,'is_active'=>true]), ['portal']);
  $g=$this->postJson('/api/admin/company',['kind'=>'group','data'=>['name'=>'Leadership','published'=>true]])->assertOk()->json('data.id');
  $p=$this->postJson('/api/admin/company',['kind'=>'person','data'=>['name'=>'Test person','group_id'=>$g,'published'=>true]])->assertOk()->json('data.id');
  $this->getJson('/api/company')->assertJsonCount(2,'data');
  $this->putJson('/api/admin/company/'.$p,['kind'=>'person','data'=>['name'=>'Test person','published'=>true,'links'=>[['label'=>'Bad','url'=>'javascript:alert(1)']]]])->assertUnprocessable();
  $this->deleteJson('/api/admin/company/'.$g)->assertOk();
  $this->getJson('/api/company')->assertJsonCount(0,'data');
 }
 public function test_applications_and_private_cv():void {
  Storage::fake('local');
  $this->postJson('/api/careers/apply',[])->assertUnprocessable();
  $this->post('/api/careers/apply',['name'=>'Test candidate','email'=>'test@example.test','consent'=>'1','resume'=>UploadedFile::fake()->create('cv.pdf',30,'application/pdf')],['Accept'=>'application/json'])->assertCreated();
  $this->getJson('/api/admin/applications')->assertUnauthorized();
  Sanctum::actingAs(User::factory()->create(['is_super_admin'=>true,'is_active'=>true]), ['portal']);
  $id=$this->getJson('/api/admin/applications')->assertOk()->json('data.0.id');
  $this->patchJson('/api/admin/applications/'.$id,['status'=>'reviewing','notes'=>'Reviewed'])->assertOk();
  $this->get('/api/admin/applications/'.$id.'/resume')->assertOk();
  $job=CompanyRecord::create(['kind'=>'job','data'=>['name'=>'Closed','published'=>false]]);
  $this->post('/api/careers/apply',['name'=>'Test','email'=>'test@example.test','consent'=>'1','job_id'=>$job->id,'resume'=>UploadedFile::fake()->create('cv.pdf',30,'application/pdf')],['Accept'=>'application/json'])->assertUnprocessable();
 }
}
