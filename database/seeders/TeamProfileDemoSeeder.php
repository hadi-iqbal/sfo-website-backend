<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\CompanyRecord;
class TeamProfileDemoSeeder extends Seeder {
 public function run():void {
  foreach(CompanyRecord::where('kind','person')->get() as $p){$d=$p->data;if(!empty($d['bio']))continue;
   $name=$d['name'];$role=$d['title'];
   $d['bio']="$name serves as $role at Security Financial Online. This role brings a people-focused approach to the team, helping colleagues coordinate their work and make information easier to understand. With an emphasis on clear communication, thoughtful service and practical next steps, $name supports SFO’s commitment to helping families plan for the future.";
   $d['full_bio']=$d['bio']."\n\nWorking across the SFO team, $name values collaboration, reliable follow-through and continuous improvement. The focus is on understanding the needs of clients and colleagues, sharing useful information and building lasting professional relationships.\n\nThis biography is demonstration copy awaiting approval.";
   $d['sample_bio']=true;
   if(empty($d['links']))$d['links']=[['label'=>$name==='Marlon Mendoza'?'LinkedIn':'LinkedIn — find profile','url'=>$name==='Marlon Mendoza'?'https://www.linkedin.com/in/marlon-mendoza-2593a6128/':'https://www.linkedin.com/search/results/people/?keywords='.rawurlencode($name),'type'=>'linkedin','visible'=>true],['label'=>'Security Financial Online','url'=>'https://securityfinancialonline.com','type'=>'website','visible'=>true]];
   $p->update(['data'=>$d]);
  }
 }
}
