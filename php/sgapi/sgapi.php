<?php

require 'JSON.php';

$qs = $_SERVER['QUERY_STRING'];

define ('APIURL','http://socialgraph.apis.google.com/lookup');
$JSON = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);

class SocialGraphApi {
	function SocialGraphApi($params) {
		// q  	Comma-separated list of URIs.  	Which node(s) in the social graph to query. -> uris
		// edo 	boolean 	Return edges out from returned nodes. -> edgesout
		// edi 	boolean 	Return edges in to returned nodes. -> edgesin
		// fme 	boolean 	Follow me links, also returning reachable nodes. -> followme
		// sgn 	boolean 	Return internal representation of nodes.
		
		$this->edgesout = isset($params['edgesout']) ? $params['edgesout'] : '0';
		$this->edgesin = isset($params['edgesin']) ? $params['edgesin'] : '0';
		$this->followme = isset($params['followme']) ? $params['followme'] : '0';
		$this->sgn = isset($params['sgn']) ? $params['sgn'] : '0';
	}
	
	
	function get($uris) {
		global $JSON;
		// is array? implode else pass
		if (is_array($uris)) {
			$uris = implode(',',$uris);
		}
		if (empty($uris)) return null;
		
		$qs = '';
		$qs .= 'q=' . $uris . 
			   '&edo=' . $this->edgesout .
			   '&edi=' . $this->edgesin .
			   '&fme=' . $this->followme .
			   '&sgn=' . $this->sgn;
		
		$f = fopen (APIURL."?$qs",'r');
		$result = '';
		while (!feof($f)) {
		  $result .= fread($f, 8192);
		}
		fclose($f);
		
		if (empty($result)) return null;
		
		//TODO: handle errors
		$this->data = $JSON->decode($result);
		return $this->data;
	}
}

$sga = new SocialGraphApi(Array('edgesout'=>0,'edgesin'=>0,'followme'=>'1'));

$mydata = $sga->get('http://redmonk.net');

echo "<pre>" . print_r($mydata,true) . "</pre>";
