<?php
class serialize {
	private static $refs=array();
	private static $refkeys=array();
	private $replace=array();
	
	public static function parse($obj) {
		$o=new serialize();
		return $o->decode($obj);
	}
	public static function stringify($obj) {
		$o=new serialize();
		return $o->encode($obj);
	}
	
	
	
	private function encode($obj) {
		if (is_scalar($obj))
			return json_encode($obj);
		$oldrefs=self::$refs;
		self::$refs=array();
		$d=$this->get($obj);
		$this->handle_refs($d['@data']);
		$p=json_encode($d);
		self::$refs=$oldrefs;
		return $p;
	}
	private function decode($str) {
		$d=json_decode($str,true);
		if (json_last_error()!=JSON_ERROR_NONE) {
			trigger_error(json_last_error_msg());
			return NULL;
		}
		if (is_scalar($d))
			return $d;
		if (!array_key_exists('@data',$d)) {
			trigger_error('Not a serialize string, no @data at root');
			return $d;
		}
		$oldrefs=self::$refs;
		self::$refs=array();
		$oldreplace=$this->replace;
		$this->replace=array();
		$p=&$this->process($d);
		$this->handle_process_refs($p);
		$this->replace=$oldreplace;
		self::$refs=$oldrefs;
		return $p;
	}
	private function get_object($o,&$info) {
		$r=new ReflectionObject($o);
		if ($r->implementsInterface('Serializable')) {
			$info['@serialize']=1;
			return $o->serialize();
		}
		$s=false;
		if (method_exists($o,'__sleep')) {
			$s=$o->__sleep();
			if (!is_array($s)) {
				trigger_error('__sleep should only bring back an array of variables');
				return;
			}
		}
		if (is_a($o,'methods')) {
			$o->callMethods('sleep_',array(&$s));
		}
		$args=0;
		$instant=false;
		if ($r->isInstantiable()) {
			$class=get_class($o);
			$constructor=$r->getConstructor();
			if ($constructor) {
				$con=$constructor->getName();
				if ($con) {
					$con=$r->getMethod($con);
					$params=$constructor->getParameters();
					$args=0;
					foreach ($params as $p) {
						if (!$p->isDefaultValueAvailable())
							$args+=1;
					}
					if (!$args) {
						try {
							$instant=new $class();
						} catch (Exception $e) {
							$instant=new stdClass;
						}
					}
				}
			} else {
				$instant=new $class;	
			}
		}

		$p=$r->getProperties(ReflectionProperty::IS_PUBLIC);
		$props=array();
		foreach ($p as $a) {
			if ($a->isStatic()) {
				// check if should store static vars
				continue;
			}
			$name=$a->getName();
			$val=&$o->{$name};
			$props[$name]=$this->get($val);
			/*if (!$args && $a->isDefault()) {
				$default=$a->getValue($instant);
				if ($default===$props[$name]) {
					unset($props[$name]);
					$info['@default']=1;
				}
			}*/			
		}
		if (!$r->hasMethod('__set_state')) {
			$info['@state']=0;
			// can only set public methods
			// need to check that $s doesn't contain any default values from class
		}
		return $props;
	}
	private function newClass($classname) {
		$r=new ReflectionClass($classname);
		$constructor=$r->getConstructor();
		if ($constructor) {
			$con=$constructor->getName();
			if ($con) {
				$con=$r->getMethod($con);
				$params=$constructor->getParameters();
				foreach ($params as $p) {
					if (!$p->isDefaultValueAvailable())
						$args+=1;
				}
				if (!$args) {
					try {
						$instant=new $classname();
					} catch (Exception $e) {
						$instant=new stdClass;
					}
				}
			}
		} else {
			$instant=new $classname;	
		}
		return $instant;
	}
	private $noref=array('sdatetime');
	private function &make_object(&$o,$info,$levels) {
		if (!array_key_exists('@class',$info)) {
			trigger_error('Unknown @class type at '.implode('.',$levels));
			return $o;
		}
		$class=$info['@class'];
		$l=implode('.',$levels);
		$c=false;
		if (!class_exists($class)) {
			$class='stdClass';
			$info['@state']=1;
			unset($info['@serialize']);
		}
		if (isset($info['@serialize'])) {
			$c=$this->newClass($class);
			$this->gotrefs[$l]=&$c;
			$c->unserialize($o);
		} elseif (isset($info['@state'])) {
			$c=$this->newClass($class); //new $class();
			$this->gotrefs[$l]=&$c;
			$loop=&$this->process_loop($o,$levels);
			foreach ($loop as $k=>&$a) {
				// TODO: test if it's overloaded
				$c->{$k}=$a;
			}
		} else {
			// use __set_state to put in data
			$data=array();
			$loop=&$this->process_loop($o,$levels);
			$lowerclass=strtolower($class);
			foreach ($loop as $k=>$a) {
				$data[$k]=$a;
			}				
			try {
				$c=call_user_func($class.'::__set_state',$data);
				foreach ($loop as $k=>&$a) {
					$c->{$k}=&$a;
				}
				//$c=$class::__set_state($loop); doesn't work with DateTime. must be because it is a reference
			} catch (Exception $e) {
				echo 'casas';
				echo $e;
			}
		}
		if ($c) {
			if (method_exists($c,'__wakeup')) {
				$c->__wakeup();
			}
			if (is_a($c,'methods'))
				$c->callMethods('wakeup_');
		}
		return $c;
	}
	private function &loop(&$obj) {
		$r=array();
		foreach ($obj as $k=>&$a) {
			$r[$k]=&$this->get($a);
		}	
		return $r;
	}
	function &get(&$obj) {
		$ref=$this->test_reference($obj);
		if ($ref) {
			$ref=array('@ref'=>$ref);
			return $ref;
		}
		if (is_null($obj) || is_scalar($obj)) {
			return $obj;
		} else {
			$o=array();
			$i=array();
			if (is_object($obj)) {
				if (is_a($obj,'stdClass')) {
					$i['@type']='std';
					$o=$this->loop($obj);
				} else {
					$i['@type']='object';
					$i['@class']=get_class($obj);
					$o=$this->get_object($obj,$i);
				}
			} else {
				if (is_array($obj)) {
					$i['@type']='array';
					$o=$this->loop($obj);
				} else {
					trigger_error('Unknown type in serializer: '.gettype($obj));
				}
			}
			$i['@data']=$o;
			return $i;
		}
	}
	private function &process_loop(&$obj,$levels) {
		$r=array();
		foreach ($obj as $k=>&$a) {
			$level=$levels;
			$level[]=$k;
			$r[$k]=&$this->process($a,$level);
		}	
		return $r;
	}
	private function &process(&$a,$levels=array()) {
		$l=implode('.',$levels);
		if (is_null($a) || is_scalar($a)) {
			$this->gotrefs[$l]=&$a;
			return $a;	
		} else {
			if (array_key_exists('@ref',$a)) {
				$r=array();
				$key=implode('.',$a['@ref']);
				if (array_key_exists($key,$this->gotrefs)) {
					$r=&$this->gotrefs[$key];
				} else {
					$this->process_ref_later($key,$r);
				}
				$this->gotrefs[$l]=&$r;
				return $r;
			}
			if (!array_key_exists('@type',$a) || !array_key_exists('@data',$a)) {
				trigger_error('serialize doesn\'t have @type or @data at level '.implode('.',$levels));
				$this->gotrefs[$l]=&$a;
				return $a;
			}					
			$type=$a['@type'];
			switch ($type) {
				case 'array':
					$r=&$this->process_loop($a['@data'],$levels);
					break;
				case 'object':
					$data=&$a['@data'];
					unset($a['@data'],$a['@type']);
					$r=&$this->make_object($data,$a,$levels);
					break;
				case 'std':
					$ra=&$this->process_loop($a['@data'],$levels);
					$r=new stdClass;
					foreach ($ra as $kk=>&$aa) {
						$r->{$kk}=&$aa;
					}
					break;	
			}
		}
		$this->gotrefs[$l]=&$r;
		return $r;
	}
	private function test_reference(&$ref) {
		self::$refs[]=&$ref;
		$found=false;
		$found_count=0;
		$previous=false;
		foreach (self::$refs as $k=>&$c) {
			if ($this->is_ref($ref,$c)) {
				$found_count+=1;
				if ($found_count>1) {
					$found=$k;
					break;
				} else {
					$previous=$k;	
				}
			}
		}
		if ($found) {
			return $previous;
		}
	}
	private function handle_refs(&$d,$levels=array()) {
		if (!$levels) {
			$oldrefkeys=self::$refkeys;
			self::$refkeys=array();
		}			
		foreach ($d as $k=>&$a) {
			$level=$levels;
			$level[]=$k;
			self::$refkeys[]=$level;	
			if (is_array($a)) {
				if (array_key_exists('@ref',$a)) {
					$a['@ref']=self::$refkeys[$a['@ref']-1];
				} else {
					if (is_array($a['@data']))
						$this->handle_refs($a['@data'],$level);
				}
			}
		}
		if (!$levels) {
			self::$refkeys=$oldrefkeys;
		}
	}
	private function process_ref_later($ref,&$item) {
		$this->replace[]=function() use (&$ref,&$item) {
			$item=&$this->gotrefs[$ref];
		};
	}
	private function handle_process_refs(&$p) {
		foreach ($this->replace as $a) {
			$a();
		}
	}
	
	// Thirdparty function: thanks <strong>BenBE at omorphia dot de</strong> slightly modified though
	
	private function is_ref(&$var1, &$var2) { 
		static $count=0;

		//If a reference exists, the type IS the same
		if(gettype($var1) !== gettype($var2)) {
			return false;
		}

		$same = false;

		//We now only need to ask for var1 to be an array ;-)
		if(is_array($var1)) {
			//Look for an unused index in $var1
			do {
				$key = "____is_ref_".$count++;
			} while(array_key_exists($key, $var1));

			//The two variables differ in content ... They can't be the same
			if(array_key_exists($key, $var2)) {
				return false;
			}

			//The arrays point to the same data if changes are reflected in $var2
			$data = "is_ref_data_".$count++;
			$var1[$key] =& $data;
			//There seems to be a modification ...
			if(array_key_exists($key, $var2)) {
				if($var2[$key] === $data) {
					$same = true;
				}
			}

			//Undo our changes ...
			unset($var1[$key]);
		} elseif(is_object($var1)) {
			if (is_a($var1,'Closure')) {
				return false; //TODO: make this check where it is defined	
			}
			//The same objects are required to have equal class names ;-)
			if(get_class($var1) !== get_class($var2)) {
				return false;
			}

			$obj1 = array_keys(get_object_vars($var1));
			$obj2 = array_keys(get_object_vars($var2));

			//Look for an unused index in $var1
			do {
				$key = "____is_ref_".$count++;
			} while(in_array($key, $obj1));

			//The two variables differ in content ... They can't be the same
			if(in_array($key, $obj2)) {
				return false;
			}

			//The arrays point to the same data if changes are reflected in $var2
			$data = "is_ref_data_".$count++;
			$var1->$key = $data;
			//There seems to be a modification ...
			if(isset($var2->$key)) {
				if($var2->$key === $data) {
					$same = true;
				}
			}

			//Undo our changes ...
			unset($var1->$key);
		} elseif (is_resource($var1)) {
			if(get_resource_type($var1) !== get_resource_type($var2)) {
				return false;
			}

			return ((string) $var1) === ((string) $var2);
		} else {
			//Simple variables ...
			if($var1!==$var2) {
				//Data mismatch ... They can't be the same ...
				return false;
			}

			//To check for a reference of a variable with simple type
			//simply store its old value and check against modifications of the second variable ;-)

			do {
				$key = "____is_ref_".$count++;
			} while($key === $var1);

			$tmp = $var1; //WE NEED A COPY HERE!!!
			$var1 = $key; //Set var1 to the value of $key (copy)
			$same = $var1 === $var2; //Check if $var2 was modified too ...
			$var1 = $tmp; //Undo our changes ...
		}

		return $same;
	}
}