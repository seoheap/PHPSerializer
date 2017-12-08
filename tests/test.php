<?php
error_reporting(E_ALL);
ini_set('display_errors','on');
include __DIR__.'/../src/serialize.php';

$test=new DateTime();
$datee=var_export($test,true);
echo htmlentities($datee);
echo eval('$date='.$datee.';');
print_r($date);

class foo {
	var $data='data';
}
class bar {
	var $data;
	function __construct($data) {
		$this->data=$data;
	}
	static function __set_state($data) {
		return new bar($data['data']);
	}
	function __wakeup() {
		$this->woken=true;
	}
}
class baz implements Serializable {
	private $data;
	function serialize() {
		return serialize($this->data);
	}
	function unserialize($data) {
		return unserialize($data);
	}
}
$foo=new foo();
$foo->foo=$foo;
$bar=new bar('test');
$baz=new baz();
$baz->bazdata=array(1,2,3);
$foo->baz=$baz;

$o=array(
	'key1'=>'test string',
	'key2'=>array(1,2,3),
	'key3'=>new DateTime(),
	'key4'=>(object)array('test'=>1,'test2'=>3,'test3'=>$bar),
	'nosetstate'=>$foo,
	'serialize'=>$baz
);

$string=serialize::stringify($o);

echo htmlentities($string);

$array=serialize::parse($string);

echo '<pre>',htmlentities(print_r($array,true)),'</pre>';

echo '<h3>PHP Standard Serialize() and Unserialize()</h3>';
$string=serialize($o);
echo htmlentities($string);
$array=unserialize($string);
echo '<pre>',htmlentities(print_r($array,true)),'</pre>';
