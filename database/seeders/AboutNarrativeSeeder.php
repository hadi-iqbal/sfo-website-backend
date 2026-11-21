<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\CompanyRecord;
class AboutNarrativeSeeder extends Seeder {
 public function run():void {
  $sections=[
   ['Who We Are','Personal guidance. A national perspective.','Security Financial Online is the public brand of Insurance Financial Solutions, a national insurance brokerage serving families and individuals since 1997. We help clients explore life insurance, mortgage protection, final expense coverage and annuities with guidance shaped around their needs and long-term goals.'],
   ['Our Mission','Clarity for the decisions that matter.','Our mission is to take the complexity out of insurance and help people find coverage that aligns with their goals, aspirations and legacy. We believe the process should bring clarity and confidence. Our team combines product knowledge with a personal approach so clients can understand their options and make informed decisions.'],
   ['Our History','Serving families since 1997.','Since 1997, Insurance Financial Solutions has focused on reliability, trust and service. Security Financial Online continues that commitment by bringing personal guidance together with digital tools. The purpose remains consistent: helping the families we serve today prepare for the generations that follow.'],
   ['Our Approach','Listen. Explain. Help you move forward.','We start by understanding what matters to you. We explain the available coverage options, help you compare appropriate products and guide you through the next steps. Our approach brings together licensed guidance, application technology and ongoing communication, with attention to your chosen beneficiaries, family priorities and future plans.']
  ];
  if(CompanyRecord::where('kind','content')->where('data->name','Who We Are')->exists())return;
  foreach(CompanyRecord::where('kind','group')->get() as $g){$d=$g->data;$d['order']=100+($d['order']??0);$g->update(['data'=>$d]);}
  foreach($sections as $i=>[$name,$intro,$description])CompanyRecord::create(['kind'=>'content','data'=>compact('name','intro','description')+['published'=>true,'order'=>$i*10]]);
 }
}
