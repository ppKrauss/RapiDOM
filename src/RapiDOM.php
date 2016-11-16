<?php
/**
 * DOMDocument for "Rapid application development".
 * Do by overloading all DOMDocument methods, and add specialized methods.
 * v0.1 2013-07-20 by ppkrauss, https://gist.github.com/ppKrauss/6099610
 *
 * Convention over configuration: UTF8, etc. by convention.
 * Like fDOMDocument, BetterDOMDocument, FluentDOM, etc, to be
 * a (more frindly interface) wrapper to DOMDocument methods.
 * Like BetterDOMDocument and fDOMDocument, but NOT extends DOMDocument
 * Like FluentDOM, but supports only chaining of XSLTs, not use CSS path, etc.
 */
class RapiDOM {
   public $dom;		// propriedade principal, faz papel de this nos métodos.
   public $dom_DTD = 'XHTML';	// XHTML|SJATS|JATS (onde SJATS e JATS requerem isMerged=false ou true)
   private $conf = array(
	'debug' => NULL,   	// true, false, or NULL for global $DEBUG
	'xslUsePHP'=>true,    	// para habilitar uso de PHP em XSLproc.
	'xsldir'=>'./xsl',      // xslt directory
   );
   private $domXslCache = array();
   private $domXPathCache=array();
   private $XSLproc = NULL;

   /**
    * Constructor.
    */
   function __construct($newconf=NULL) {
	$this->domRestart($newconf,true);
   }

   /**
    * $dom initializer.
    */
   function domRestart($newconf=NULL,$domDiscard=false) {
	$this->setConfigs($newconf);
	if ($domDiscard || !$this->dom)
	   	$this->dom = new DOMDocument('1.0', 'UTF-8'); // standard enforced.
	$this->dom->resolveExternals = false; // external entities from a (HTML) doctype declaration
	$this->dom->recover = false; // Libxml2 proprietary behaviour. Enables recovery mode, i.e. trying to parse non-well formed documents
	// read only $this->dom->xmlEncoding = 'utf-8'; //(redundante) part of the XML declaration, the encoding of this document.
	$this->dom->xmlStandalone = true; // part of the XML declaration, whether this document is standalone
	$this->domXPathCache = array();
   }

   /**
    * Set and Get $conf.
    */
   function setConfigs(&$newconf=NULL) {
	if ($newconf && is_array($newconf)) {
		global $DEBUG;
		if ( !array_key_exists('debug',$newconf) || $newconf['debug']===NULL )
			$newconf['debug']=$DEBUG;
		$this->conf = array_merge($this->conf,$newconf);
	} // else: no effect
   }
   function getConfigs() {return $this->conf;}


   /**
    * Overhead. Create a "extends DOMDocument" behaviour.
    */
   function __call($name, $args) {
	return call_user_func_array(array($this->dom, $name), $args);
   }

   /**
    * Options, convetions for check by in_array($opts).
    */
   private function options2array($options) {
	if ($options)
		return preg_split( '/[\s,;]+/s', strtolower(trim($options)) );
	else
		return array();
   }

   // // // // // // // // // // // // // //
   // // BEGIN:IO (load/save methods): // //

   /**
    * Alernative for DomDocument saveXML() method, using friendly options.
    * Valid options: cutXmlHead trimRoot omitdoctype.
    * DANGER: trimRoot needs root, is not balanced (cut start and end tags when are the same).
    * Private use of $xml parameter (see transformToXML method).
    */
   function saveXML($options='',$xml='') {
	$options  = $this->options2array($options);
	if (!$xml)
		$xml = ( in_array('cutxmlhead',$options) )?
			$this->dom->saveXML($this->dom->documentElement):  // without head
			$this->dom->saveXML();				   // with head
	if ( in_array('omitdoctype',$options) )
		$xml = preg_replace('#^(\s*(?:<\?xml[^>]+>\s*)?)<\!DOCTYPE\s[^>]+>#s', '$1', $xml);

	static $REGEX = '#^(\s*(?:<\?xml[^>]+>\s*)?)<([a-z]+)[^>]*>(.+?)</\2>(\s*)$#s';
	if ( in_array('trimroot',$options) && preg_match($REGEX,$xml,$m))
		return "$m[1]$m[3]$m[4]"; //preg_replace($REGEX, '$1$3$4', $xml);
	else
		return $xml;
   }

   /**
    * Alernative for DomDocument load() method, using direct DomDocument.
    */
   function loadDoc(&$dom,$newconf=NULL) { // do clone.
	$this->dom = $dom;
	$this->domRestart($newconf,false);
   }

   /**
    * Façade for DomDocument load(filename string) and loadXML(XML string) methods.
    * Detecting by isXmlString method.
    */
   function loadStr(&$xml,$getBasePath=1,$trimTag=0) {
	if ($this->isXmlString($xml)) {
		$this->domRestart(NULL,false);
		$this->dom->loadXML($xml);    		// load XML (or XHTML) from string
	} else { // is a file path
		$this->domRestart(NULL,false);
		$this->dom->load($xml,LIBXML_NSCLEAN); 	// load XML from file
	}
   }

   /**
    * Detect if string is XML (true) or filename (false).
    */
   private function isXmlString(&$s, $FILEN=500) {
	if (strlen($s) > $FILEN)
		return true;
	else
		return (strpos($s,'<')===FALSE)? false: true;
   }
   // //  END:IO  // //
   // // // // // // //


   // // // // // // // // // // // // // // // // //
   // // BEGIN:XSLT (SET, CACHE AND TRANSFORM): // //

   /**
    * Set or get a XSLT-DomDocument cache by name.
    * @param $name: a item from "xsldir/name.xsl", or a label for a XSLT string.
    * @param $xslstr: XML or filename of a XSLT.
    * Cache of XSLT stored at database: see $this->conf['xsldir'].
    */
   function xsl_set($name,&$xslstr=NULL) {
	$isnew=true;
	if ( array_key_exists($name,$this->domXslCache) )
		$isnew=false;
	else
		$this->domXslCache[$name] = new DOMDocument('1.0', 'UTF-8');
	if ($xslstr) {
		if ($this->isXmlString($xslstr)) {
			$this->addXslIntoXslFrame($xslstr); // afeta $xslstr
			$this->domXslCache[$name]->loadXML($xslstr);
		} elseif ($xslstr=='db') {
			die("error: db is a reserved name for future use");
		} else
			$this->domXslCache[$name]->load($xslstr);
	} elseif ($isnew)
		$this->domXslCache[$name]->load("{$this->conf['xsldir']}/$name.xsl");

	if (!$this->XSLproc) { // once
		$this->XSLproc = new XSLTProcessor();
		$this->XSLproc->registerPHPFunctions();
		if ($this->conf['xslUsePHP'])
			$this->XSLproc->registerPHPFunctions();
	} // or cache XSLTProcessors? very bigger or not? change speed on chaining?
	$this->XSLproc->importStylesheet($this->domXslCache[$name]);

	return true;  // falta gerir erro antes do retorno
   }

   /**
    * Like transformToDoc() method, but affects $dom.
    * Supports chaining: $doc->transformToThis('name1')->transformToThis('name2');
    * Params $name and $xslstr: see xsl_set() method.
    */
   function transformToThis($name,$xslstr='') {
	$this->xsl_set($name,$xslstr);
	$this->dom = $this->XSLproc->transformToDoc($this->dom);
	return $this;
   }

   /**
    * Direct transformToDoc() method, transforming $dom by $name.
    */
   function transformToDoc($name,$xslstr='') {
	$this->xsl_set($name,$xslstr);
	return  $this->XSLproc->transformToDoc($this->dom);
   }

   /**
    * Direct transformToXML() method, transforming $dom by $name.
    */
   function transformToXML($name,$xslstr='',$options='') {
	$this->xsl_set($name,$xslstr);
	if ($options)
		return  $this->saveXML($options, $this->XSLproc->transformToXML($this->dom) );
	else
		return  $this->XSLproc->transformToXML($this->dom);
   }

   /**
    * Check when is "simplifyed XSLT", adding the "XSLT frame".
    */
   function addXslIntoXslFrame(&$xsl, $method='xml', $encoding='utf-8', $indent='yes') {
	$out = "<xsl:output method=\"$method\" encoding=\"$encoding\" indent=\"$indent\"/>";
	static $xslblockTemplate = '<?xml version="1.0" encoding="UTF-8"?>
		<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
			xmlns:php="http://php.net/xsl" exclude-result-prefixes="php">
		###_xslblockTemplate_###
		</xsl:stylesheet>
	';
	if (!preg_match('|</xsl:stylesheet>\s*$|',$xsl)) // XSL frame signature
		$xsl = str_replace('###_xslblockTemplate_###',"$out\n$xsl",$xslblockTemplate);
   }
   // // END:XSLT // //
   // // // // // // //

   // // // // // // // // // //
   // //  BEGIN:NODECHANGE // //

   /**
    * Prepare nodes of the updating.
    * Returns: importNode of $insNode.
    * $insNode not changes.
    * $nodeOrPath changes if is a XPath (backs as node).
    */
   private function insPrepare(&$insNode, &$nodeOrPath) {
	if ($nodeOrPath===NULL)
		$nodeOrPath = '/'; // root
	if (!is_object($nodeOrPath)) {
		$xp = new DOMXpath($this->dom);
		$nodeOrPath = $xp->query($nodeOrPath)->item(0);
	}
	if ($insNode!==NULL && !is_object($insNode)) {
		$tmp = new DOMDocument('1.0', 'UTF-8');
		$tmp->loadXML($insNode);
		$tmp = $tmp->documentElement;
	} else  // falta IF tipo==dondocumengt, usa documentElement!
		$tmp = $insNode;
	//or elseif (insNode===null) ...?
	return $this->dom->importNode($tmp, TRUE); // todos são no this->dom
   }

   /**
    * Inserts a node into (before) a this->dom node or XPath.
    */
   function insertBefore($insNode,$nodeOrPath=NULL) {
	$import = $this->insPrepare($insNode,$nodeOrPath);
	$nodeOrPath->parentNode->insertBefore($import,$nodeOrPath);
   }

   /**
    * Inserts a node into (after) a this->dom node or XPath.
    */
   function appendNode($insNode,$nodeOrPath=NULL) {
	$import = $this->insPrepare($insNode,$nodeOrPath);
	$nodeOrPath->appendChild($import);
   }

   /**
    * Replaces a this->dom node or XPath by a new node.
    * Motivation http://stackoverflow.com/q/17864378/287948
    */
   function replaceNode($node,$nodeOrPath,$options='') {
		$options = $this->options2array($options);
	if ($nodeOrPath===NULL)  // ou identidade, fica ele mesmo
		die("erro: path nulo invalido em replaceNode");
	$import = $this->insPrepare($node,$nodeOrPath);
	$nodeOrPath->parentNode->replaceChild($import,$nodeOrPath);
   }

   // // END:NODECHANGE // //
   // // // // // // // // //


} // class RapiDOM
