<?php
namespace phan;

function add_class($class_name) {
	global $classes, $internal_arginfo, $internal_classvars;

	$lc = strtolower($class_name);
	$class = new \ReflectionClass($class_name);
	$flags = 0;
	if($class->isFinal()) $flags = \ast\flags\CLASS_FINAL;
	else if($class->isInterface()) $flags = \ast\flags\CLASS_INTERFACE;
	else if($class->isTrait()) $flags = \ast\flags\CLASS_TRAIT;
	if($class->isAbstract()) $flags |= \ast\flags\CLASS_ABSTRACT;

	$classes[$lc] = ['file'=>'internal',
					 'namespace'=>$class->getNamespaceName(),
					 'conditional'=>false,
					 'flags'=>$flags,
					 'lineno'=>0,
					 'endLineno'=>0,
					 'name'=>$class_name,
					 'docComment'=>'', // Internal classes don't have docComments
					 'type'=>'',
					 'traits'=>[]
					];

	$parent = $class->getParentClass();
	if(!$parent) $classes[$lc]['parent'] = '';
	else $classes[$lc]['parent'] = $parent->getName();

	foreach($class->getDefaultProperties() as $name=>$value) {
		$type = $internal_classvars[strtolower($class_name)]['properties'][$name] ?? type_map(gettype($value));
#		echo "{$class_name}::{$name} = ($type) $value\n";
		$prop = new \ReflectionProperty($class_name, $name);
		$classes[$lc]['properties'][strtolower($name)] = [
											'flags' => $prop->getModifiers(),
											'name'  => $name,
											'lineno'=> 0,
											'type' => type_map(gettype($value)) ];
	}

	$classes[$lc]['interfaces'] = $class->getInterfaceNames();
	$classes[$lc]['traits'] = $class->getTraitNames();
	$parents = [];
	$parent = $class->getParentClass();
	if($parent) {
		$temp = $class;
		while($parent = $temp->getParentClass()) {
			$parents[] = $parent->getName();
			$parents = array_merge($parents, $parent->getInterfaceNames());
			$temp = $parent;
		}
	}

	$types = [$class_name];
	$types = array_merge($types, $classes[$lc]['interfaces']);
	$types = array_merge($types, $parents);
	$classes[$lc]['type'] = implode('|', array_unique($types));

	foreach($class->getConstants() as $name=>$value) {
		$classes[$lc]['constants'][$name] = [	'name'  => $name,
												'lineno'=> 0,
												'type' => type_map(gettype($value)) ];
	}

	foreach($class->getMethods() as $method) {
		$meth = new \ReflectionMethod($class_name, $method->name);
		$required = $meth->getNumberOfRequiredParameters();
		$optional = $meth->getNumberOfParameters() - $required;
		$lmname = strtolower($method->name);
		$classes[$lc]['methods'][$lmname] = [
                                              'file'=>'internal',
                                              'namespace'=>$class->getNamespaceName(),
                                              'conditional'=>false,
                                              'flags'=>$meth->getModifiers(),
                                              'lineno'=>0,
                                              'endLineno'=>0,
                                              'name'=>$method->name,
                                              'docComment'=>'',
                                              'required'=>$required,
                                              'optional'=>$optional,
                                              'ret'=>null
		                                    ];
		$arginfo = [];
		unset(${"arginfo1"});
		$alt = 1;

		if(!empty($internal_arginfo["{$class_name}::{$method->name}"])) {
			$arginfo = $internal_arginfo["{$class_name}::{$method->name}"];
			$classes[$lc]['methods'][$lmname]['ret'] = $arginfo[0];
			while(!empty($internal_arginfo["{$class_name}::{$method->name} $alt"])) {
				$classes[$lc]['methods']["$lmname $alt"] = $classes[$lc]['methods'][$lmname];
				${"arginfo{$alt}"} = $internal_arginfo["{$class_name}::{$method->name} $alt"];
				unset(${"arginfo".($alt+1)});
				$classes[$lc]['methods']["$lmname $alt"]['ret'] = ${"arginfo{$alt}"}[0];
				$alt++;
			}
		} else if(!empty($parents)) {
			foreach($parents as $parent_name) {
				if(!empty($internal_arginfo["{$parent_name}::{$method->name}"])) {
					$classes[$lc]['methods']["$lmname $alt"] = $classes[$lc]['methods'][$lmname];
					$arginfo = $internal_arginfo["{$parent_name}::{$method->name}"];
					$classes[$lc]['methods'][$lmname]['ret'] = $arginfo[0];
					while(!empty($internal_arginfo["{$parent_name}::{$method->name} $alt"])) {
						${"arginfo{$alt}"} = $internal_arginfo["{$parent_name}::{$method->name} $alt"];
						unset(${"arginfo".($alt+1)});
						$classes[$lc]['methods']["$lmname $alt"]['ret'] = ${"arginfo{$alt}"}[0];
						$alt++;
					}
					break;
				}
			}
		}

		foreach($method->getParameters() as $param) {
			$alt = 1;
			$flags = 0;
			if($param->isPassedByReference()) $flags |= \ast\flags\PARAM_REF;
			if($param->isVariadic()) $flags |= \ast\flags\PARAM_VARIADIC;
			$classes[$lc]['methods'][strtolower($method->name)]['params'][] =
				 [ 'file'=>'internal',
				   'flags'=>$flags,
				   'lineno'=>0,
				   'name'=>$param->name,
#				   'type'=>$param->getType(),
				   'type'=>(empty($arginfo) ? null : next($arginfo)),
				   'def'=>null
				 ];
			while(!empty(${"arginfo{$alt}"})) {
				$classes[$lc]['methods'][strtolower($method->name).' '.$alt]['params'][] =
					 [ 'file'=>'internal',
					   'flags'=>$flags,
					   'lineno'=>0,
					   'name'=>$param->name,
#					   'type'=>$param->getType(),
					   'type'=>(empty(${"arginfo{$alt}"}) ? null : next(${"arginfo{$alt}"})),
					   'def'=>null
					 ];

				$alt++;
			}
		}
	}
}

function add_internal($internal_classes) {
	global $functions, $internal_arginfo;

	foreach($internal_classes as $class_name) {
		add_class($class_name);
	}
	foreach(get_declared_interfaces() as $class_name) {
		add_class($class_name);
	}
	foreach(get_declared_traits() as $class_name) {
		add_class($class_name);
	}

	foreach(get_defined_functions()['internal'] as $function_name) {
		$function = new \ReflectionFunction($function_name);
		$required = $function->getNumberOfRequiredParameters();
		$optional = $function->getNumberOfParameters() - $required;
		$functions[strtolower($function_name)] = [
			 'file'=>'internal',
			 'namespace'=>$function->getNamespaceName(),
			 'avail'=>true,
			 'conditional'=>false,
			 'flags'=>0,
			 'lineno'=>0,
			 'endLineno'=>0,
			 'name'=>$function_name,
			 'docComment'=>'',
			 'required'=>$required,
			 'optional'=>$optional,
			 'ret'=>null,
			 'params'=>[]
	    ];
		add_param_info($function_name);

	}
	foreach(array_keys($internal_arginfo) as $function_name) {
		if(strpos($function_name, ':')!==false) continue;
		$ln = strtolower($function_name);
		$functions[$ln] = [
			 'file'=>'internal',
			 'avail'=>false,
			 'conditional'=>false,
			 'flags'=>0,
			 'lineno'=>0,
			 'endLineno'=>0,
			 'name'=>$function_name,
			 'docComment'=>'',
			 'ret'=>null,
			 'params'=>[]
	    ];
		add_param_info($function_name);
	}
}

function add_param_info($function_name) {
	global $internal_arginfo, $functions;

	$lfn = strtolower($function_name);

	if(!empty($internal_arginfo[$function_name])) {
		// If we have the signature in arginfo.php, use it
		$req = $opt = 0;
		foreach($internal_arginfo[$function_name] as $k=>$v) {
			if($k===0) {
				$functions[$lfn]['ret'] = $v;
				continue;
			}
			$flags = 0;
			if($k[0]=='&') $flags |= \ast\flags\PARAM_REF;
			if(strpos($k,'...')!==false) $flags |= \ast\flags\PARAM_VARIADIC;
			if(strpos($k,'=')!==false) $opt++;
			else $req++;
			$functions[$lfn]['params'][] = [
				'flags'=>$flags,
				'lineno'=>0,
				'name'=>$k,
				'type'=>$v,
				'def'=>null
			];
		}
		$functions[$lfn]['required'] = $req;
		$functions[$lfn]['optional'] = $opt;
		// Check for alternate signatures
		$alt = 1;
		$req = $opt = 0;
		while(!empty($internal_arginfo["$function_name $alt"])) {
			// Copy the main part
			$functions["$lfn $alt"] = $functions[$lfn];
			$functions["$lfn $alt"]['params'] = [];
			// Then parse the alternate signature
			foreach($internal_arginfo["$function_name $alt"] as $k=>$v) {
				if($k===0) {
					$functions["$lfn $alt"]['ret'] = $v;
					continue;
				}
				$flags = 0;
				if($k[0]=='&') $flags |= \ast\flags\PARAM_REF;
				if(strpos($k,'...')) $flags |= \ast\flags\PARAM_VARIADIC;
			if(strpos($k,'=')!==false) $opt++;
			else $req++;
				$functions["$lfn $alt"]['params'][] = [
					'flags'=>$flags,
					'lineno'=>0,
					'name'=>$k,
					'type'=>$v,
					'def'=>null
				];
			}
			$functions["$lfn $alt"]['required'] = $req;
			$functions["$lfn $alt"]['optional'] = $opt;
			$alt++;
		}
	} else {
		$function = new \ReflectionFunction($function_name);
		foreach($function->getParameters() as $param) {
			$flags = 0;
			if($param->isPassedByReference()) $flags |= \ast\flags\PARAM_REF;
			if($param->isVariadic()) $flags |= \ast\flags\PARAM_VARIADIC;
			$functions[$lfn]['params'][] = [
				'flags'=>$flags,
				'lineno'=>0,
				'name'=>$param->name,
				'type'=>'',  // perhaps $param->getType() one day when we fix this in PHP
				'def'=>null
			];
		}
	}
}

// Add a type to a scope
function add_type(string $cs, string $var, $type) {
	global $scope;

	if(!empty($scope[$cs]['vars'][$var]) && !empty($scope[$cs]['vars'][$var]['type'])) {
		foreach(explode('|',$type) as $t) {
			if(strpos('|'.$scope[$cs]['vars'][$var]['type'].'|', '|'.$t.'|') === false) {
				// add this new possible type if we haven't seen it before
				$scope[$cs]['vars'][$var]['type'] = $scope[$cs]['vars'][$var]['type'] . '|' . $t;
			}
		}
		$scope[$cs]['vars'][$var]['type'] = trim($scope[$cs]['vars'][$var]['type'],'|');
	} else {
		$scope[$cs]['vars'][$var]['type'] = $type;
	}
}

// Finds the variable name
function var_name($node):string {
	if(!$node instanceof \ast\Node) return (string)$node;
	$parent = $node;
	while(($node instanceof \ast\Node) && ($node->kind != \ast\AST_VAR) && ($node->kind != \ast\AST_STATIC) &&($node->kind != \ast\AST_MAGIC_CONST)) {
		$parent = $node;
		$node = $node->children[0];
	}
	if(!$node instanceof \ast\Node) return (string)$node;
	if(empty($node->children[0])) return '';
	if($node->children[0] instanceof \ast\Node) return '';
	return (string)$node->children[0];
}

function find_class_name(string $file, $node, string $namespace, $current_class, $current_scope, &$static_call_ok=false):string {
	global $classes, $scope;

	if(!$node instanceof \ast\Node) return $node;
	$class_name = $return = '';

	switch($node->kind) {
		case \ast\AST_NEW:
		case \ast\AST_STATIC_CALL:
		case \ast\AST_CLASS_CONST:
			if($node->children[0]->kind == \ast\AST_NAME) {
				$class_name = $node->children[0]->children[0];
				if($class_name == 'self' || $class_name == 'static' || $class_name == 'parent') {
					if(!$current_class) {
						Log::err(Log::ESTATIC, "Cannot access {$class_name}:: when no class scope is active", $file, $node->lineno);
						return '';
					}
					if($class_name == 'static') {
						$class_name = $current_class['name'];
					} else if($class_name == 'self') {
						if($current_scope != 'global') list($class_name,) = explode('::', $current_scope);
						else $class_name = $current_class['name'];
					}
					else if($class_name == 'parent') $class_name = $current_class['parent'];
					$static_call_ok = true;
				} else {
					$class_name = qualified_name($file, $node->children[0], $namespace);
				}
			}
			break;

		case \ast\AST_INSTANCEOF:
			if($node->children[1]->kind == \ast\AST_NAME) {
				$class_name = qualified_name($file, $node->children[1], $namespace);
			}
			break;

		case \ast\AST_METHOD_CALL:
		case \ast\AST_PROP:
			if($node->children[0]->kind == \ast\AST_VAR) {
				if(!($node->children[0]->children[0] instanceof \ast\Node)) {
					// $var->method()
					if($node->children[0]->children[0] == 'this') {
						if(!$current_class) {
							Log::err(Log::ESTATIC, 'Using $this when not in object context', $file, $node->lineno);
							return '';
						}
					}
					if(empty($scope[$current_scope]['vars'][$node->children[0]->children[0]])) {
						// Got lost, couldn't find the variable in the current scope
						// If it really isn't defined, it will be caught by the undefined var error
						return '';
					}
					$call = $scope[$current_scope]['vars'][$node->children[0]->children[0]]['type'];
					// Hack - loop through the possible types of the var and assume first found class is correct
					foreach(explode('|', nongenerics($call)) as $class_name) {
						if(!empty($classes[strtolower($class_name)])) break;
					}
					if(empty($class_name)) return '';
					$class_name = $classes[strtolower($class_name)]['name'] ?? $class_name;
				}
			} else if($node->children[0]->kind == \ast\AST_PROP) {
				$prop = $node->children[0];
				if($prop->children[0]->kind == \ast\AST_VAR && !($prop->children[0]->children[0] instanceof \ast\Node)) {
					// $var->prop->method()
					$var = $prop->children[0];
					if($var->children[0] == 'this') {
						if(!$current_class) {
							Log::err(Log::ESTATIC, 'Using $this when not in object context', $file, $node->lineno);
							return '';
						}
						if(!($prop->children[1] instanceof \ast\Node)) {
							if(!empty($current_class['properties'][$prop->children[1]])) {
								$prop = $current_class['properties'][$prop->children[1]];
								foreach(explode('|', nongenerics($prop['type'])) as $class_name) {
									 if(!empty($classes[strtolower($class_name)])) break;
								}
								if(empty($class_name)) return '';
								$class_name = $classes[strtolower($class_name)]['name'] ?? $class_name;
							}
						} else {
							// $this->$prop->method() - too dynamic, give up
							return '';
						}
					}
				}
			}
			break;
	}

	if($class_name) {
		$lc = strtolower($class_name);
		switch($node->kind) {
			case \ast\AST_NEW:
				if(empty($classes[$lc])) {
					if(!is_native_type(type_map($class_name))) Log::err(Log::EUNDEF, "Trying to instantiate undeclared class {$class_name}", $file, $node->lineno);
				} else if($classes[$lc]['flags'] & \ast\flags\CLASS_ABSTRACT) {
					if(!is_native_type(type_map($class_name))) {
						if($current_scope != 'global') list($scope_class,) = explode('::', $current_scope);
						else $scope_class = '';
						$lsc = strtolower($scope_class);
						if(($lc != $lsc) || ($lc == $lsc && strtolower($current_class['name']) != $lsc)) Log::err(Log::ETYPE, "Cannot instantiate abstract class {$class_name}", $file, $node->lineno);
					}
				} else if($classes[$lc]['flags'] & \ast\flags\CLASS_INTERFACE) {
					if(!is_native_type(type_map($class_name))) Log::err(Log::ETYPE, "Cannot instantiate interface {$class_name}", $file, $node->lineno);
				} else {
					$return = $class_name;
				}
				break;

			case \ast\AST_INSTANCEOF:
				if(empty($classes[$lc])) {
					if(!is_native_type(type_map($class_name))) Log::err(Log::EUNDEF, "instanceof called on undeclared class {$class_name}", $file, $node->lineno);
				} else {
					$return = $class_name;
				}
				break;

			case \ast\AST_CLASS_CONST:
				if(empty($classes[$lc])) {
					if(!is_native_type(type_map($class_name))) Log::err(Log::EUNDEF, "can't access constant from undeclared class {$class_name}", $file, $node->lineno);
				} else {
					$return = $class_name;
				}
				break;

			case \ast\AST_STATIC_CALL:
				if(empty($classes[$lc])) {
					if(!is_native_type(type_map($class_name))) Log::err(Log::EUNDEF, "static call to undeclared class {$class_name}", $file, $node->lineno);
				} else {
					$return = $class_name;
				}
				break;

			case \ast\AST_METHOD_CALL:
				if(empty($classes[$lc])) {
					if(!is_native_type(type_map($class_name))) Log::err(Log::EUNDEF, "call to method on undeclared class {$class_name}", $file, $node->lineno);
				} else {
					$return = $class_name;
				}
				break;

			case \ast\AST_PROP:
				if(empty($classes[$lc])) {
					if(!is_native_type(type_map($class_name))) Log::err(Log::EUNDEF, "can't access property from undeclared class {$class_name}", $file, $node->lineno);
				} else {
					$return = $class_name;
				}
				break;
		}
	}
	return $return;
}

// Figures out the qualified name for an AST_NAME node
function qualified_name(string $file, $node, string $namespace) {
	global $namespace_map;

	if(!($node instanceof \ast\Node) && $node->kind != \ast\AST_NAME) {
		return var_name($node);
	}
	$name = $node->children[0];
	$lname = strtolower($name);
	if($node->flags & \ast\flags\NAME_NOT_FQ) {
		// is it a simple native type name?
		if(is_native_type($lname)) return $name;

		// Not fully qualified, check if we have an exact namespace alias for it
		if(!empty($namespace_map[T_CLASS][$file][$lname])) {
			return $namespace_map[T_CLASS][$file][$lname];
		}

		// Check for a namespace-relative alias
		if(($pos = strpos($lname, '\\'))!==false) {
			$first_part = substr($lname, 0, $pos);
			if(!empty($namespace_map[T_CLASS][$file][$first_part])) {
				// Replace that first aliases part and return the full name
				return $namespace_map[T_CLASS][$file][$first_part] . '\\' . substr($name, $pos + 1);
			}
		}

		// No aliasing, just prepend the namespace
		return $namespace.$name;
	} else {
		// It is already fully qualified, just return it
		return $name;
	}
}

function is_native_type(string $type):bool {
	return in_array(strtolower(str_replace('[]','',$type)), ['int','float','bool','true','string','callable','array','null','object','resource','mixed','void']);
}

// Checks if the types, and if a union, all types in the union, are scalar
function type_scalar($type):bool {
	static $typemap = ['integer'=>'int','double'=>'float','boolean'=>'bool','true'=>'bool','false'=>'bool','callback'=>'callable'];
	if(empty($type) || $type=='mixed') return false;
	$type = $typemap[$type] ?? $type;
	if($type=='int' ||  $type=='float' || $type=='string') return true;
	if(strpos("|$type|", '|mixed|') !== false) return false;
	$type = type_map($type);

	// our own union types
	if(strpos($type,'|')) {
		foreach(explode('|', $type) as $s) {
			if($s!='int' &&  $s!='float' && $s!='string') return false;
		}
	} else {
		return false;
	}
	return true;
}

// Maps type names to consistent names
function type_map(string $type):string {
	static $repmaps = [ ['integer', 'double',  'boolean', 'false', 'true', 'callback', 'closure', 'NULL'],
                        ['int',     'float',   'bool',    'bool',  'bool', 'callable', 'callable', 'null']];
	return str_replace($repmaps[0], $repmaps[1], $type);
}

// Looks for any suspicious GPSC variables in the given node
function var_taint_check($file, $node, string $current_scope):bool {
	global $scope, $tainted_by;

	static $tainted = ['_GET'=>'*', '_POST'=>'*', '_COOKIE'=>'*', '_REQUEST'=>'*', '_FILES'=>'*',
					   '_SERVER'=>[	'QUERY_STRING', 'HTTP_HOST', 'HTTP_USER_AGENT',
									'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE',
									'REQUEST_URI', 'PHP_SELF', 'argv'] ];

	if(!$node instanceof \ast\Node) return false;
	$parent = $node;
	while(($node instanceof \ast\Node) && ($node->kind != \ast\AST_VAR) && ($node->kind != \ast\AST_MAGIC_CONST)) {
		$parent = $node;
		if(empty($node->children[0])) break;
		$node = $node->children[0];
	}

	if($parent->kind == \ast\AST_DIM) {
		if($node->children[0] instanceof \ast\Node) {
			// $$var or something else dynamic is going on, not direct access to a suspivious var
			return false;
		}
		foreach($tainted as $name=>$index) {
			if($node->children[0] === $name) {
				if($index=='*') return true;
				if($parent->children[1] instanceof \ast\Node) {
					// Dynamic index, give up
					return false;
				}
				if(in_array($parent->children[1], $index, true)) {
					return true;
				}
			}
		}
	} else if($parent->kind == \ast\AST_VAR && !($parent->children[0] instanceof \ast\Node)) {
		if(empty($scope[$current_scope]['vars'][$parent->children[0]])) {
			if(!superglobal($parent->children[0]))
				Log::err(Log::EVAR, "Variable \${$parent->children[0]} is not defined", $file, $parent->lineno);
		} else {
			if(!empty($scope[$current_scope]['vars'][$parent->children[0]]['tainted'])) {
				$tainted_by = $scope[$current_scope]['vars'][$parent->children[0]]['tainted_by'];
				return true;
			}
		}
	}
	return false;
}

// Merges a new type string into an existing avoiding dupes
function merge_type($existing, $add) {
	// short-circuit if we already have it
	if(strpos("|$existing|","|$add|")!==false) return $existing;
	// or it is empty
	if(empty($existing)) return $add;
	if(empty($add)) return $existing;

	foreach(explode('|',$add) as $t) {
		if(!empty($t) && strpos("|$existing|", "|$t|") === false) {
			$existing .= '|'.$t;
		}
	}
	return $existing;
}

function expand_interfaces($interfaces, $new) {
    global $classes;

	$interfaces = array_merge($interfaces, $new);
	foreach($new as $interface) {
		$node = $classes[strtolower($interface)];
		if(!empty($node['interfaces'])) {
            $interfaces = expand_interfaces($interfaces, $node['interfaces']);
		}
	}
    return $interfaces;
}

function add_var_scope(string $cs, string $name, string $type, $replace_type = false) {
	global $scope;

	if(empty($name)) return;
	if(!array_key_exists($cs, $scope)) $scope[$cs] = [];
	if(!array_key_exists('vars', $scope[$cs])) $scope[$cs]['vars'] = [];
	if(array_key_exists($name, $scope[$cs]['vars'])) {
		if($replace_type) {
			$scope[$cs]['vars'][$name]['type'] = $type;
		} else {
			// If we previously have seen a specific type for this variable and now get a ''
			// which means we don't know a type, we at least know it can be something else, so
			// add in a 'mixed' here
			if($type==='' && !empty($scope[$cs]['vars'][$name]['type'])) {
				$type = 'mixed';
			}
			$scope[$cs]['vars'][$name]['type'] = merge_type($scope[$cs]['vars'][$name]['type'], $type);
		}
	} else {
		$scope[$cs]['vars'][$name] = ['type'=>$type, 'tainted'=>false, 'tainted_by'=>''];
	}
}

function superglobal(string $var):bool {
	return in_array($var, ['_GET','_POST','_COOKIE','_REQUEST','_SERVER','_ENV','_FILES','_SESSION','GLOBALS']);
}

function modifiers(int $flags):string {
	$result = '';
	if($flags & \ast\flags\MODIFIER_PUBLIC) {
		$result = 'public';
	} else if(($flags & \ast\flags\MODIFIER_PROTECTED)) {
		$result = 'protected';
	} else if(($flags & \ast\flags\MODIFIER_PRIVATE)) {
		$result = 'private';
	}
	if($flags & \ast\flags\MODIFIER_FINAL) {
		$result .= ' final';
	}
	if($flags & \ast\flags\MODIFIER_ABSTRACT) {
		$result .= ' abstract';
	}
	if($flags & \ast\flags\MODIFIER_STATIC) {
		$result .= ' static';
	}
	return trim($result);
}

function check_classes(&$classes, $pc_required = []) {
	global $namespace_map;

	foreach($classes as $name=>$class) {
		if(strpos($name, ':')!==false) {
			list(,$class_name) = explode(':',$name,2);
			if($class['flags'] & \ast\flags\CLASS_INTERFACE) $class_str = "Interface";
			else if($class['flags'] & \ast\flags\CLASS_TRAIT) $class_str = "Trait";
			else $class_str = "Class";

			$orig = $classes[strtolower($class_name)];
			if($orig['flags'] & \ast\flags\CLASS_INTERFACE) $orig_str = "Interface";
			else if($orig['flags'] & \ast\flags\CLASS_TRAIT) $orig_str = "Trait";
			else $orig_str = "Class";

			if($orig['file'] == 'internal') {
				Log::err(Log::EREDEF, "{$class_str} {$class_name} defined at {$class['file']}:{$class['lineno']} was previously defined as {$orig_str} {$class_name} internally", $class['file'], $class['lineno']);
			} else {
				Log::err(Log::EREDEF, "{$class_str} {$class_name} defined at {$class['file']}:{$class['lineno']} was previously defined as {$orig_str} {$class_name} at {$orig['file']}:{$orig['lineno']}", $class['file'], $class['lineno']);
			}
		} else {
			if($class['file']!=='internal') {
				$parents = [];
				$temp = $class;
				while(!empty($temp['parent'])) {
					if(empty($classes[strtolower($temp['parent'])])) {
						Log::err(Log::EUNDEF, "Trying to inherit from unknown class {$temp['parent']}", $class['file'], $class['lineno']);
						break;
					} else if(($classes[strtolower($temp['parent'])]['flags'] & \ast\flags\CLASS_FINAL)) {
						Log::err(Log::ETYPE, "{$class['name']} may not inherit from final class {$classes[strtolower($temp['parent'])]['name']}", $class['file'], $class['lineno']);
					}
					$temp = $classes[strtolower($temp['parent'])];
					$parents[] = $temp['name'];
					if(!empty($temp['interfaces'])) $parents = array_merge($parents, $temp['interfaces']);
				}
				$types = [$class['name']];
				if(!empty($class['interfaces'])) {
					foreach($class['interfaces'] as $interface) {
						if(empty($classes[strtolower($interface)])) {
							Log::err(Log::EUNDEF, "Trying to implement unknown interface {$interface}", $class['file'], $class['lineno']);
						} else if(!($classes[strtolower($interface)]['flags'] & \ast\flags\CLASS_INTERFACE)) {
							Log::err(Log::ETYPE, "Trying to implement interface $interface which is not an interface", $class['file'], $class['lineno']);
						}
					}
					$types = array_merge($types, $class['interfaces']);
				}
				if(!empty($parents)) $types = array_merge($types, $parents);
				// Fill in type from inheritance tree and interfaces
				$classes[$name]['type'] = merge_type($classes[$name]['type'], implode('|', array_unique($types)));
				foreach($pc_required as $c) {
					if($class['name'] != $c && in_array($c, $types)) {
						if(!$class['pc_called']) Log::err(Log::ETYPE, "{$class['name']} extends $c but doesn't call parent::__construct()", $class['file'], $class['lineno']);
					}
				}
				foreach($class['methods'] as $k=>$method) {
					if(!($class['methods'][$k]['flags'] & \ast\flags\MODIFIER_STATIC)) {
						if($class['flags'] & \ast\flags\CLASS_TRAIT) {
							// We don't know the type yet for methods in a trait
							add_var_scope("{$class['name']}::{$method['name']}", 'this', '');
						} else {
							add_var_scope("{$class['name']}::{$method['name']}", 'this', $classes[$name]['type']);
						}
					}
				}
			}
		}
	}
}

function check_functions($functions) {
	foreach($functions as $name=>$func) {
		if(strpos($name, ':')!==false) {
			list(,$func_name) = explode(':',$name,2);
			$orig = $functions[strtolower($func_name)];
			if($orig['file'] == 'internal') {
				if($func['conditional'] == true) continue;
				Log::err(Log::EREDEF, "Function {$func_name} defined at {$func['file']}:{$func['lineno']} was previously defined internally", $func['file'], $func['lineno']);
			} else {
				Log::err(Log::EREDEF, "Function {$func_name} defined at {$func['file']}:{$func['lineno']} was previously defined at {$orig['file']}:{$orig['lineno']}", $func['file'], $func['lineno']);
			}
		}
	}
}

function dump_scope($scope) {
	foreach($scope as $k=>$v) {
		echo $k."\n".str_repeat("\u{00AF}",strlen($k))."\n";
		echo " Variables:\n";
		foreach($v['vars'] as $kk=>$vv) {
			echo "\t$kk: {$vv['type']}";
			if($vv['tainted']) {
				echo "(tainted";
				if($vv['tainted_by']) echo ':'.$vv['tainted_by'];
				echo ")";
			}
			if(!empty($vv['param'])) echo " (param: {$vv['param']})";
			echo "\n";
		}
		echo "\n";
	}
}

function dump_functions($type='user') {
	global $classes, $functions, $namespace_map;
	$temp = "Global functions";
	echo $temp."\n".str_repeat("\u{00AF}", strlen($temp))."\n";

	foreach($functions as $k=>$func) {
		if($func['file'] != 'internal') {
			echo "{$func['name']}(";
			$pstr = '';
			if(!empty($func['params'])) foreach($func['params'] as $k=>$param) {
				$type = !empty($param['type']) ? "{$param['type']} " : '';
				if($k>$func['required']) $pstr .= '[';
				$pstr .= "{$type}{$param['name']}";
				if($k>$func['required']) $pstr .= ']';
				$pstr .= ', ';
			}
			echo trim($pstr,', ');
			echo ')';
			if(!empty($func['ret'])) echo ':'.$func['ret'];
			echo "\n";
		}
	}
	echo "\n\n";

	foreach($classes as $class=>$entry) {
		if($entry['file']=='internal') continue;
		$temp = "class ".$entry['name'];
		if(!empty($entry['parent'])) $temp .= " extends {$entry['parent']}";
		if(!empty($entry['type'])) $temp .= " types: {$entry['type']}";
		echo $temp."\n".str_repeat("\u{00AF}", strlen($temp))."\n";

		if(!empty($entry['constants'])) {
			foreach($entry['constants'] as $const) {
				echo "\tconst {$const['name']} = {$const['type']}\n";
			}
			echo "\n";
		}

		if(!empty($entry['properties'])) {
			foreach($entry['properties'] as $prop) {
				$mod = modifiers($prop['flags']);
				echo "\t$mod \${$prop['name']} = {$prop['type']}\n";
			}
			echo "\n";
		}

		if(!empty($entry['methods'])) foreach($entry['methods'] as $func) {
			if($func['file'] != 'internal') {
				if($func['flags'] & \ast\flags\MODIFIER_STATIC) {
					echo "\t {$classes[$class]['name']}::{$func['name']}(";
				} else {
					echo "\t {$classes[$class]['name']}->{$func['name']}(";
				}
				$pstr = '';
				if(!empty($func['params'])) foreach($func['params'] as $k=>$param) {
					$type = !empty($param['type']) ? "{$param['type']} " : '';
					if($k>$func['required']) $pstr .= '[';
					$pstr .= "{$type}{$param['name']}";
					if($k>$func['required']) $pstr .= ']';
					$pstr .= ', ';
				}
				echo trim($pstr,', ');
				echo ')';
				if(!empty($func['ret'])) echo ':'.$func['ret'];
				echo "\n";
			}
		}
		echo "\n";
	}

	if(!empty($namespace_map[T_CLASS])) {
		$temp = "Namespace class aliases";
		echo $temp."\n".str_repeat("\u{00AF}", strlen($temp))."\n";
		foreach($namespace_map[T_CLASS] as $file=>$entries) {
			echo "\t$file:\n";
			foreach($entries as $alias=>$target) {
				echo "\t\t$alias => $target\n";
			}
		}
		echo "\n";
	}

	if(!empty($namespace_map[T_FUNCTION])) {
		$temp = "Namespace function aliases";
		echo $temp."\n".str_repeat("\u{00AF}", strlen($temp))."\n";
		foreach($namespace_map[T_FUNCTION] as $file=>$entries) {
			echo "\t$file:\n";
			foreach($entries as $alias=>$target) {
				echo "\t$alias => $target\n";
			}
		}
		echo "\n";
	}

	if(!empty($namespace_map[T_CONST])) {
		$temp = "Namespace constant aliases";
		echo $temp."\n".str_repeat("\u{00AF}", strlen($temp))."\n";
		foreach($namespace_map[T_CONST] as $file=>$entries) {
			echo "\t$file:\n";
			foreach($entries as $alias=>$target) {
				echo "\t$alias => $target\n";
			}
		}
		echo "\n";
	}
}

function walk_up($nodes, $node) {
	global $classes;
	$nodes[] = $node;
	$n = $classes[strtolower($node)];
	if(!empty($n['interfaces'])) {
		foreach($n['interfaces'] as $int) {
			if(empty($classes[strtolower($int)])) continue;
			$nodes = walk_up($nodes, $int);
		}
	}
	if(!empty($n['traits'])) {
		$nodes = array_merge($nodes, $n['traits']);
	}
	if(!empty($n['parent'])) {
		$nodes = walk_up($nodes, $n['parent']);
	}
	return $nodes;
}

function walk_down($nodes, $node) {
	global $classes;
	if(empty($classes[strtolower($node)])) return $nodes;
	$n = $classes[strtolower($node)];
	// This could take a while...
	foreach($classes as $k=>$c) {
		if(!empty($c['traits'])) {
			if(in_array($node, $c['traits'])) $nodes[] = $c['name'];
		}
		if(!empty($c['interfaces'])) {
			if(in_array($node, $c['traits'])) {
				$nodes[] = $c['name'];
				$nodes = walk_down($nodes, $c['name']);
			}
		}
		if(!empty($c['parent']) && $c['parent'] == $node) {
			$nodes[] = $c['name'];
			$nodes = walk_down($nodes, $c['name']);
		}
	}
	return $nodes;
}
function dump_gv($node) {
	global $classes;

	$root = [];
	$interfaces = [];
	$traits = [];
	$namespaces = [];
	$all = [];
	if($node) {
		if(empty($classes[strtolower($node)])) Log::err(Log::EFATAL, "{$node} not found");
		$nodes = [];
		$nodes = walk_up($nodes, $node);
		$nodes = walk_down($nodes, $node);
		foreach($nodes as $cn) {
			$all[] = $classes[strtolower($cn)];
		}
	} else {
		$all = $classes;
	}

	foreach($all as $key=>$entry) {
		$ns = empty($entry['namespace']) ? '' : $entry['namespace'];
		// Classify the entries first so we can colour them appropriately later
		if($entry['flags'] & \ast\flags\CLASS_INTERFACE) {
			$interfaces[$ns][$key] = $entry['name'];
			$namespaces[$ns]=true;
		} else if($entry['flags'] & \ast\flags\CLASS_TRAIT) {
			$traits[$ns][$key] = $entry['name'];
			$namespaces[$ns]=true;
		} else if(empty($entry['parent'])) {
			$root[$ns][$key] = $entry['name'];
			$namespaces[$ns]=true;
		} else {
			$nodes[$ns][$key] = $entry['name'];
			$namespaces[$ns]=true;
		}
		// Then work out the connections
		foreach($entry['interfaces'] as $v) {
			if(empty($classes[strtolower($v)])) continue;
			$fe = $classes[strtolower($v)];
			if($entry['file']!='internal' || $fe['file']!='internal') {
				$conns[$fe['namespace']][$fe['name']][] = $entry['name'];
				$seen[$fe['name']] = true;
				$seen[$entry['name']] = true;
			}
		}
		foreach($entry['traits'] as $v) {
			if(empty($classes[strtolower($v)])) continue;
			$fe = $classes[strtolower($v)];
			if($entry['file']!='internal' || $fe['file']!='internal') {
				$conns[$fe['namespace']][$fe['name']][] = $entry['name'];
				$seen[$fe['name']] = true;
				$seen[$entry['name']] = true;
			}
		}
		if(!empty($entry['parent'])) {
			if(empty($classes[strtolower($entry['parent'])])) continue;
			$fe = $classes[strtolower($entry['parent'])];
			if($entry['file']!='internal' || $fe['file']!='internal') {
				$conns[$fe['namespace']][$fe['name']][] = $entry['name'];
				$seen[$fe['name']] = true;
				$seen[$entry['name']] = true;
			}
		}
	}

	// Now get rid of anything internal that doesn't have a direct connection to something in user-space
	$temp = $interfaces;
	foreach($temp as $ns=>$vv) {
		foreach($vv as $k => $vv) {
			$entry = $classes[strtolower($vv)];
			if($entry['file']=='internal' && empty($seen[$vv])) {
				unset($interfaces[$ns][$k]);
			}
		}
	}
	$temp = $traits;
	foreach($temp as $ns=>$vv) {
		foreach($vv as $k => $vv) {
			$entry = $classes[strtolower($vv)];
			if($entry['file']=='internal' && empty($seen[$vv])) unset($traits[$ns][$k]);
		}
	}

	$temp = $root;
	foreach($temp as $ns=>$vv) {
		foreach($vv as $k => $vv) {
			$entry = $classes[strtolower($vv)];
			if($entry['file']=='internal' && empty($seen[$vv])) unset($root[$ns][$k]);
		}
	}

	echo "digraph class_graph {
	graph [ overlap=false, fontsize=6, size=\"80,60\"]
	node  [ shape=box, style=filled, color=SandyBrown, fontcolor=Black, fontname=Utopia]\n\n";

	foreach(array_keys($namespaces) as $i=>$ns) {
		if($ns!='') {
			// Each namespace is a subgraph
			echo "subgraph cluster_$i {\n";
			echo "label=\"".trim(str_replace('\\','\\\\',$ns), '\\')."\";\n";
			echo "style=filled;\n";
			echo "color=lightgrey;\n";
		}
		if(!empty($interfaces[$ns])) foreach($interfaces[$ns] as $v) {
			echo ($v===$node) ? gv($v,"shape=doubleoctagon, color=Gold"):gv($v);
		}
		echo "\n";
		echo "node [ shape=box, color=YellowGreen, fontcolor=Black, fontname=Utopia]\n";
		if(!empty($traits[$ns])) foreach($traits[$ns] as $v) {
			echo ($v===$node) ? gv($v,"shape=doubleoctagon, color=Gold"):gv($v);
		}
		echo "\n";
		echo "node [ shape=box, color=Wheat, fontcolor=Black, fontname=\"Utopia-Bold\"]\n";
		if(!empty($root[$ns])) foreach($root[$ns] as $v) {
			echo ($v===$node) ? gv($v,"shape=doubleoctagon, color=Gold"):gv($v);
		}
		echo "\n";
		echo "node [ shape=box, color=Wheat, fontcolor=Black, fontname=Utopia]\n";
		if(!empty($nodes[$ns])) foreach($nodes[$ns] as $key=>$v) {
			echo ($v===$node) ? gv($v,"shape=doubleoctagon, color=Gold"):gv($v);
		}
		echo "\n";

		if(!empty($conns[$ns])) foreach($conns[$ns] as $key=>$nns) {
			foreach($nns as $n) {
				if($classes[strtolower($key)]['flags'] & \ast\flags\CLASS_INTERFACE) $style = 'dotted';
				else if($classes[strtolower($key)]['flags'] & \ast\flags\CLASS_TRAIT) $style = 'dashed';
				else $style = 'solid';
				echo gvline($key, $n, $style);
			}
		}
		if($ns!='') {
			echo "}\n";
		}
	}
	echo "}\n";
}

function gv($a, $style='') {
	return '"'.str_replace('\\','\\\\',$a)."\"".($style?"[$style]":'').";\n";
}

function gvline($a,$b,$style='') {
	return '"'.str_replace('\\','\\\\',$a).'"->"'.str_replace('\\','\\\\',$b).'" '.($style?"[style=\"$style\"]":'')."\n";
}
/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: sw=4 ts=4 fdm=marker
 * vim<600: sw=4 ts=4
*/
