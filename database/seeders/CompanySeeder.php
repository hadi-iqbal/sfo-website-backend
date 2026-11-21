<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\CompanyRecord;
class CompanySeeder extends Seeder {
 public function run():void {
  if(CompanyRecord::exists())return;
  $groups=[['Corporate Leadership',[['Marlon Mendoza','Founder & Chief Visionary Officer','mendoza.webp'],['Muhammad Talha','Chief Technology Officer','Talha.png'],['Fizza Kazim','Human Resources Manager','Fizza.webp'],['Ahsan Abdullah Slatch','Operations Manager','Ahsan.webp'],['Brad Kingsberg','National Agents Liaison','Brad Kingsberg.webp']]],['Licensed Agents',[['Matthew Cortes','Licensed Agent','Matt.webp'],['Michele Pelton Bennett','Licensed Agent','Michele.webp'],['Zainab Awadallah','Licensed Agent','Zainab Awadallah.webp'],['Jackson Davis','Licensed Agent','jacksondavis.webp']]],['Support Team',[['Sanwal Zayn Baig','Team Manager','Sanwal.png'],['Fahaad Shahzaad','Exam Liaison','Fahaad Shahzad.webp']]],['Core Systems',[['Muhammad Bin Fahd','Vendors Liaison & Tech Solutions Expert','Muhammad Bin Fahd.webp']]]];
  foreach($groups as $order=>[$name,$people]){$g=CompanyRecord::create(['kind'=>'group','data'=>['name'=>$name,'order'=>$order,'published'=>true]]);foreach($people as $i=>[$person,$title,$image])CompanyRecord::create(['kind'=>'person','data'=>['name'=>$person,'title'=>$title,'image'=>'/team/'.$image,'group_id'=>$g->id,'order'=>$i,'published'=>true,'bio'=>'','focal'=>35]]);}
  foreach(['Licensed Agent','Intern','Front-end Caller','Exam Associate'] as $i=>$title)CompanyRecord::create(['kind'=>'job','data'=>['name'=>$title,'published'=>false,'order'=>$i,'description'=>'Add the approved responsibilities, requirements and application details before publishing.']]);
  foreach([['How do I apply?','Choose a published opportunity and submit the application with your PDF résumé. You may also send a general introduction.'],['Do I need an insurance license?','Licensing requirements depend on the role. Review the individual opening for its requirements.'],['Are internships available?','Internship opportunities will appear here when published. You can also submit a general application.'],['What happens after I apply?','Our team reviews applications and contacts candidates when there is a suitable next step.']] as $i=>[$q,$a])CompanyRecord::create(['kind'=>'faq','data'=>['name'=>$q,'answer'=>$a,'order'=>$i,'published'=>true]]);
 }
}
