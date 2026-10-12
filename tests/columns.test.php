<?php
// Standalone column contracts. No WordPress records or credentials are touched.
if (!defined('ABSPATH')) define('ABSPATH','/unused/');
class WP_Error {
 public $code; public $message; public $data;
 public function __construct($code,$message,$data=[]){$this->code=$code;$this->message=$message;$this->data=$data;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_parse_args($values,$defaults){return array_merge($defaults,$values);}
function get_option($key,$default=false){return $GLOBALS['bi_qa_options'][$key]??$default;}
function add_option($key,$value,...$args){if(array_key_exists($key,$GLOBALS['bi_qa_options']))return false;$GLOBALS['bi_qa_options'][$key]=$value;return true;}
function update_option($key,$value,...$args){$GLOBALS['bi_qa_options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['bi_qa_options'][$key]);return true;}
function wp_generate_uuid4(){return '12345678-abcd-1234-abcd-'.str_pad((string)++$GLOBALS['bi_qa_uuid'],12,'0',STR_PAD_LEFT);}
function sanitize_text_field($v){return trim(strip_tags($v));}
function sanitize_textarea_field($v){return trim(strip_tags($v));}
function sanitize_key($v){return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/','',$v));}
function absint($v){return abs((int)$v);}
function current_user_can($cap){return $GLOBALS['bi_qa_admin'];}
function register_rest_route($namespace,$route,$args){$GLOBALS['bi_qa_routes'][$route]=$args;}
require __DIR__.'/../includes/class-boardino.php';
$GLOBALS['bi_qa_count']=0;$GLOBALS['bi_qa_uuid']=0;$GLOBALS['bi_qa_options']=[];$GLOBALS['bi_qa_admin']=true;
function expect($ok,$message){$GLOBALS['bi_qa_count']++;if(!$ok)throw new \Exception($message);}
$settings=Boardino::defaults();$settings['members']=[1,5];$settings['folder_id']=12;$settings['categories']=[['id'=>'existing','name'=>'Priority','color'=>'purple']];$settings['extra_setting']='preserved';
$GLOBALS['bi_qa_options']=['boardino_settings'=>$settings,'boardino_revision'=>7];
Boardino::routes();$route=$GLOBALS['bi_qa_routes']['/columns'];
expect($route['methods']==='POST','Column creation uses POST');
expect(call_user_func($route['permission_callback'])===true,'Administrator can add columns');
$GLOBALS['bi_qa_admin']=false;$denied=call_user_func($route['permission_callback']);expect(is_wp_error($denied)&&$denied->data['status']===403,'A board member cannot change administrator-owned columns');$GLOBALS['bi_qa_admin']=true;
$result=call_user_func($route['callback'],['name'=>'  Review  ','revision'=>7]);
expect(!is_wp_error($result)&&$result['column']['name']==='Review','Append a named column');
expect($result['revision']===8&&get_option('boardino_revision')===8,'Return the new revision');
$updated=get_option('boardino_settings');expect(array_slice($updated['columns'],0,3)===$settings['columns'],'Keep the three default columns and their order');
$without=$updated;$without['columns']=$settings['columns'];expect($without===$settings,'Keep users, categories, folder, and extra settings');
expect(preg_match('/^col_[a-f0-9]{32}$/D',$result['column']['id'])===1,'Generated ID fits existing column format');
expect(get_option('boardino_write_lock')===false,'Release the write lock');
$validate=new \ReflectionMethod(Boardino::class,'validate_card');if(PHP_VERSION_ID<80100)$validate->setAccessible(true);
$card=$validate->invoke(null,['title'=>'Task','description'=>'','column'=>$result['column']['id'],'category'=>'','assignee'=>0]);expect(!is_wp_error($card),'New columns are valid card statuses');
$stale=Boardino::add_column(['name'=>'Another column','revision'=>7]);expect(is_wp_error($stale)&&$stale->data['status']===409,'Reject a stale board revision');expect(get_option('boardino_settings')===$updated&&get_option('boardino_revision')===8,'A stale add never overwrites settings');
foreach(['', '   ', str_repeat('x',61), ['bad'], 42] as $bad){$error=Boardino::add_column(['name'=>$bad,'revision'=>8]);expect(is_wp_error($error),'Reject empty, long, or non-text names');expect(get_option('boardino_settings')===$updated&&get_option('boardino_revision')===8,'Invalid additions do not change the board');expect(get_option('boardino_write_lock')===false,'An invalid name releases the lock');}
$second=Boardino::add_column(['name'=>'<b>Waiting</b>','revision'=>8]);expect($second['column']['name']==='Waiting','Store a plain text column name');expect($second['column']['id']!==$result['column']['id'],'Each column has its own identity');
$full=Boardino::settings();while(count($full['columns'])<30)$full['columns'][]=['id'=>'fill_'.count($full['columns']),'name'=>'Column'];$GLOBALS['bi_qa_options']['boardino_settings']=$full;
$limit=Boardino::add_column(['name'=>'Too many','revision'=>9]);expect(is_wp_error($limit),'Apply the same 30-column limit as Settings');expect(get_option('boardino_settings')===$full&&get_option('boardino_revision')===9,'Limit failures preserve every existing column');
echo 'PASS: '.$GLOBALS['bi_qa_count']." column checks\n";
