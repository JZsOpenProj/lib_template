<?php

/*
$_config = array();
$_config['system']['template'] = '';//template文件根目录
$_config['system']['data'] = '';//data文件根目录
*/
class template {

	var $tpldir;
	var $objdir;

	var $tplfile;
	var $objfile;
	var $langfile;

	var $vars;
	var $force = 0;

	var $var_regexp = "\@?\\\$[a-zA-Z_]\w*(?:\[[\w\.\"\'\[\]\$]+\])*";//任意变量及数组变量
	var $vtag_regexp = "\<\?=(\@?\\\$[a-zA-Z_]\w*(?:\[[\w\.\"\'\[\]\$]+\])*)\?\>";//php标签，echo任意变量及数组变量，捕获echo内容
	var $const_regexp = "\{([\w]+)\}";

	var $languages = array();
	var $sid;

	function __construct() {
		ob_start();
		$template_config=$GLOBALS['_config']['system'];
		$this->defaulttpldir = $template_config['template'].'default';
		$this->tpldir = $template_config['template'].'default';
		$this->objdir = $template_config['data'].'view';
		$this->langfile = $template_config['template'].'default/templates.lang.php';
		if (version_compare(PHP_VERSION, '5') == -1) {
			register_shutdown_function(array(&$this, '__destruct'));
		}
	}

	function assign($k, $v) {
		$this->vars[$k] = $v;
	}

	function display($file) {
		extract($this->vars, EXTR_SKIP);
		include $this->gettpl($file);
	}

	function gettpl($file) {
		isset($_REQUEST['inajax']) && ($file == 'header' || $file == 'footer') && $file = $file.'_ajax';
		isset($_REQUEST['inajax']) && ($file == 'admin_header' || $file == 'admin_footer') && $file = substr($file, 6).'_ajax';
		$this->tplfile = $this->tpldir.'/'.$file.'.htm';
		$this->objfile = $this->objdir.'/'.$file.'.php';
		$tplfilemtime = @filemtime($this->tplfile);
		if($tplfilemtime === FALSE) {
			$this->tplfile = $this->defaulttpldir.'/'.$file.'.htm';
		}
		if($this->force || !file_exists($this->objfile) || @filemtime($this->objfile) < filemtime($this->tplfile)) {
			if(empty($this->language)) {
				@include $this->langfile;
				if(isset($languages)&&is_array($languages)) {
					$this->languages += $languages;
				}
			}
			$this->complie();
		}
		return $this->objfile;
	}

	function complie() {
		$template = file_get_contents($this->tplfile);
		$template = preg_replace("/\<\!\-\-\{(.+?)\}\-\-\>/s", "{\\1}", $template);//将<!--{变量}-->转化为{变量}
		$template = preg_replace("/\{lang\s+(\w+?)\}/ise", "\$this->lang('\\1')", $template);

		$template = preg_replace("/\{($this->var_regexp)\}/", "<?=\\1?>", $template);//显示{变量}中的变量
		$template = preg_replace("/\{($this->const_regexp)\}/", "<?=\\1?>", $template);//显示{{常量}}中的常量
		$template = preg_replace("/(?<!\<\?\=|\\\\)$this->var_regexp/", "<?=\\0?>", $template);//排除已编译或注释后的php变量

		$template = preg_replace("/\<\?=(\@?\\\$[a-zA-Z_]\w*)((\[[\\$\[\]\w]+\])+)\?\>/ies", "\$this->arrayindex('\\1', '\\2')", $template);//显示索引方括号无单引号的数组

		$template = preg_replace("/\{\{eval (.*?)\}\}/ies", "\$this->stripvtag('<? \\1?>')", $template);//执行php语句{{eval php语句}}
		$template = preg_replace("/\{eval (.*?)\}/ies", "\$this->stripvtag('<? \\1?>')", $template);//执行php语句{eval php语句}
		$template = preg_replace("/\{for (.*?)\}/ies", "\$this->stripvtag('<? for(\\1) {?>')", $template);//执行for语句{for php语句}

		$template = preg_replace("/\{elseif\s+(.+?)\}/ies", "\$this->stripvtag('<? } elseif(\\1) { ?>')", $template);//执行elseif语句{elseif php语句}

		for($i=0; $i<2; $i++) {//2层嵌套循环 (loop 数组 键 值 循环体语句)
			$template = preg_replace("/\{loop\s+$this->vtag_regexp\s+$this->vtag_regexp\s+$this->vtag_regexp\}(.+?)\{\/loop\}/ies", "\$this->loopsection('\\1', '\\2', '\\3', '\\4')", $template);
			$template = preg_replace("/\{loop\s+$this->vtag_regexp\s+$this->vtag_regexp\}(.+?)\{\/loop\}/ies", "\$this->loopsection('\\1', '', '\\2', '\\3')", $template);
		}
		$template = preg_replace("/\{if\s+(.+?)\}/ies", "\$this->stripvtag('<? if(\\1) { ?>')", $template);//执行if语句{if php语句}

		$template = preg_replace("/\{template\s+(\w+?)\}/is", "<? include \$this->gettpl('\\1');?>", $template);//编译并载入其他模板{template 模板名}
		$template = preg_replace("/\{template\s+(.+?)\}/ise", "\$this->stripvtag('<? include \$this->gettpl(\\1); ?>')", $template);//动态编译并载入其他模板{template 变量名}


		$template = preg_replace("/\{else\}/is", "<? } else { ?>", $template);//{else}
		$template = preg_replace("/\{\/if\}/is", "<? } ?>", $template);//{/if}
		$template = preg_replace("/\{\/for\}/is", "<? } ?>", $template);//{/for}

		$template = preg_replace("/$this->const_regexp/", "<?=\\1?>", $template);//显示{常量}中的常量

		$template = "<? if(!defined('IN_SSY')) exit('Access Denied');?>\r\n$template";//添加头
		$template = preg_replace("/(\\\$[a-zA-Z_]\w+\[)([a-zA-Z_]\w+)\]/i", "\\1'\\2']", $template);//在没有单引号的数组索引方括号中加入单引号

		$template = preg_replace("/\<\?(\s{1})/is", "<?php\\1", $template);//将<?改成<?php
		$template = preg_replace("/\<\?\=(.+?)\?\>/is", "<?php echo \\1;?>", $template);/*将<?=变量?>改成<?echo 变量?>*/
        //写php文件
		$fp = fopen($this->objfile, 'w');
		fwrite($fp, $template);
		fclose($fp);
	}

	function arrayindex($name, $items) {
		$items = preg_replace("/\[([a-zA-Z_]\w*)\]/is", "['\\1']", $items);//在没有单引号的数组索引方括号中加入单引号
		return "<?=$name$items?>";
	}

	function stripvtag($s) {/*除去 表达式中的<?=?>*/
		return preg_replace("/$this->vtag_regexp/is", "\\1", str_replace("\\\"", '"', $s));
	}

	function loopsection($arr, $k, $v, $statement) {
		$arr = $this->stripvtag($arr);
		$k = $this->stripvtag($k);
		$v = $this->stripvtag($v);
		$statement = str_replace("\\\"", '"', $statement);
		return $k ? "<? foreach((array)$arr as $k => $v) {?>$statement<? }?>" : "<? foreach((array)$arr as $v) {?>$statement<? } ?>";
	}

	function lang($k) {
		return !empty($this->languages[$k]) ? $this->languages[$k] : "{ $k }";
	}

	function _transsid($url, $tag = '', $wml = 0) {
		$sid = $this->sid;
		$tag = stripslashes($tag);
		if(!$tag || (!preg_match("/^(http:\/\/|mailto:|#|javascript)/i", $url) && !strpos($url, 'sid='))) {
			if($pos = strpos($url, '#')) {
				$urlret = substr($url, $pos);
				$url = substr($url, 0, $pos);
			} else {
				$urlret = '';
			}
			$url .= (strpos($url, '?') ? ($wml ? '&amp;' : '&') : '?').'sid='.$sid.$urlret;
		}
		return $tag.$url;
	}

	function __destruct() {
		if(isset($_COOKIE['sid'])) {
			return;
		}
		$sid = rawurlencode($this->sid);
		$searcharray = array(
			"/\<a(\s*[^\>]+\s*)href\=([\"|\']?)([^\"\'\s]+)/ies",
			"/(\<form.+?\>)/is"
		);
		$replacearray = array(
			"\$this->_transsid('\\3','<a\\1href=\\2')",
			"\\1\n<input type=\"hidden\" name=\"sid\" value=\"".rawurldecode(rawurldecode(rawurldecode($sid)))."\" />"
		);
		$content = preg_replace($searcharray, $replacearray, ob_get_contents());
		ob_end_clean();
		echo $content;
	}

}

/*

Usage:
require_once 'lib/template.class.php';
$this->view = new template();
$this->view->assign('page', $page);
$this->view->assign('userlist', $userlist);
$this->view->display("user_ls");

*/

?>