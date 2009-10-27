<?php

require_once( 'rssconf.php');
require_once( '../../config.php');
require_once( '../../util.php');
require_once( '../../Snoopy.class.inc');

class rRSS
{
	public $items = array();
	public $channel = array();
	public $url;
	public $srcURL;
	public $hash;
	public $cookies = array();
	public $lastModified = null;
	public $etag = null;
	public $encoding = null;
	public $version = 1;
	private $channeltags = array ('title', 'link', 'lastBuildDate');
	private $itemtags = array('title', 'link', 'pubDate', 'enclosure', 'guid', 'source', 'description', 'dc:date');

	public function rRSS( $url = null )
	{
		if($url)
		{
			$pos = strpos($url,':COOKIE:');
			if($pos!==false)
			{
				$this->url = substr($url,0,$pos);
				$tmp = explode(";",substr($url,$pos+8));
				foreach($tmp as $item)
				{
					list($name,$val) = explode("=",$item);
					$this->cookies[$name] = $val;
				}
			}
			else
				$this->url = $url;
			$this->url = self::linkencode($this->url);
			$this->srcURL = $this->url;
			if(count($this->cookies))
			{
				$this->srcURL = $this->srcURL.':COOKIE:';
				foreach($this->cookies as $name=>$val)
					$this->srcURL = $this->srcURL.$name.'='.$val.';';
				$this->srcURL = substr($this->srcURL,0,strlen($this->srcURL)-1);
			}
			$this->hash = md5( $this->srcURL );
		}
	}

	public function getTorrent( $href )
	{
		$cli = self::fetchURL(self::linkencode($href),$this->cookies);
		if($cli && $cli->status>=200 && $cli->status<300)
			return($cli->results);
		return(false);
	}

	public function getContents($label,$auto,$enabled,$history)
	{
		$ret = "{ label: \"".self::quoteInvalidURI($label)."\", auto: ".$auto.", enabled: ".$enabled.", hash: \"".$this->hash."\", url: \"".self::quoteInvalidURI($this->srcURL)."\", items: [";
		foreach($this->items as $href=>$item)
		{
			if($item['timestamp']>0)
				$ret.="{ time: ".$item['timestamp'];
			else
				$ret.='{ time: null';
			$ret.=", title: \"".addslashes($item['title'])."\", href: \"".self::quoteInvalidURI($href)."\", errcount: ".$history->getCounter($href).", hash: \"".$history->getHash($href)."\" },";
		}
		$len = strlen($ret);
		if($ret[$len-1]==',')
			$ret = substr($ret,0,$len-1);
		return($ret.'] }');
	}

	public function fetch()
	{
		$headers = array();
		if($this->etag) 
			$headers['If-None-Match'] = trim($this->etag);
		if($this->lastModified)
	                $headers['If-Last-Modified'] = trim($this->lastModified);
		$cli = self::fetchURL($this->url,null,$headers);
		if($cli->status==304)
			return(true);
		$this->etag = null;
		$this->lastModified = null;
		if($cli->status>=200 && $cli->status<300)
		{
			foreach($cli->headers as $h) 
			{
				$h = trim($h);
				if(strpos($h, ": "))
					list($name, $val) = explode(": ", $h, 2);
				else
				{
					$name = $h;
					$val = "";
				}
				if( $name == 'ETag' ) 
					$this->etag = $val;
				else				
				if( $name == 'Last-Modified' )
					$this->lastModified = $val;
			}
			ini_set( "pcre.backtrack_limit", strlen($cli->results) );
			$this->encoding = strtolower($this->search("'encoding=[\'\"](.*?)[\'\"]'si", $cli->results));
			if($this->encoding=='')
				$this->encoding = null;
			$this->channel = array();
			if(preg_match("'<channel.*?>(.*?)</channel>'si", $cli->results, $out)==1)
			{
				foreach($this->channeltags as $channeltag)
				{
					$temp = $this->search("'<$channeltag.*?>(.*?)</$channeltag>'si", $out[1], ($channeltag=='title'));
					if($temp!='')
						$this->channel[$channeltag] = $temp;
				}
				if( array_key_exists('lastBuildDate',$this->channel) &&
				        (($timestamp = strtotime($this->channel['lastBuildDate'])) !==-1))
					$this->channel['timestamp'] = $timestamp;
				else
					$this->channel['timestamp'] = 0;
			}
			else
				$this->channel['timestamp'] = 0;
			$this->items = array();
			$ret = preg_match_all("'<item(| .*?)>(.*?)</item>'si", $cli->results, $items);
			if(($ret!==false) && ($ret>0))
			{
				foreach($items[2] as $rssItem)
				{
					$item = array();
					foreach($this->itemtags as $itemtag)
					{
						if($itemtag=='enclosure')
							$temp = $this->search("'<enclosure.*url\s*=\s*\"(.*?)\".*>'si", $rssItem);
						else
						if($itemtag=='source')
							$temp = $this->search("'<source\s*url\s*=\s*\"(.*?)\".*>.*</source>'si", $rssItem);
						else
						if($itemtag=='title')
						{
							$temp = $this->search("'<$itemtag.*?>(.*?)</$itemtag>'si", $rssItem, true);
							$temp = preg_replace("/\s+/u"," ",$temp);
						}
						else
							$temp = $this->search("'<$itemtag.*?>(.*?)</$itemtag>'si", $rssItem, false);
						if($itemtag=='description')
							$temp = html_entity_decode( $temp, ENT_QUOTES, "UTF-8" );
						if($temp != '')
						{
							$item[$itemtag] = $temp;
							if( (($itemtag=='pubDate') || ($itemtag=='dc:date')) &&
								(($timestamp = strtotime($temp)) !==-1))
								$item['timestamp'] = $timestamp;
						}
					}
					$href = '';
					if(array_key_exists('enclosure',$item))
						$href = $item['enclosure'];
					else
					if(array_key_exists('link',$item))
						$href = $item['link'];
					else
					if(array_key_exists('guid',$item))
						$href = $item['guid'];
					else
					if(array_key_exists('source',$item))
						$href = $item['source'];
					if(!array_key_exists('timestamp',$item))
					{
						
// hack for iptorrents.com
// Category: Movies/Non-English  Size: 707.38 MB Added: 2009-10-21 07:42:37
						if(array_key_exists('description',$item) && 
							(strlen($item['description'])<255) &&
							(($pos = strpos($item['description'],'Added: '))!==false) &&
							(($timestamp = strtotime(substr($item['description'],$pos+7)))!==-1))
							$item['timestamp'] = $timestamp;
						else
							$item['timestamp'] = 0;
					}
					if(!empty($href))
						$this->items[self::removeTegs( $href )] = $item;
				}
			}
			return(true);
		}
		return(false);
	}

	static public function removeTegs( $s )
	{
		$last = '';
		while($s!=$last)
		{
			$last = $s;
			$s = @html_entity_decode( strip_tags($s), ENT_QUOTES, "UTF-8" );
		}
		return($s);
	}

	protected function search($pattern, $subject, $needTranslate = false)
	{
		preg_match($pattern, $subject, $out);
		if(isset($out[1]))
		{
			$out[1] = strtr($out[1], array('<![CDATA['=>'', ']]>'=>''));
			if($this->encoding && $this->encoding!="utf-8")
				$out[1] = $this->convert($out[1], $needTranslate);
			else
			if($needTranslate)
				$out[1] = self::removeTegs( $out[1] );
			return(trim($out[1]));
		}
		else
			return('');
	}

	protected function convert($out,$needTranslate = false)
	{
		if($needTranslate)
			$out = self::removeTegs( $out );
		if(function_exists('iconv'))
			$out = iconv($this->encoding, 'UTF-8//TRANSLIT', $out);
		else
                if(function_exists('mb_convert_encoding'))
			$out = mb_convert_encoding($out, 'UTF-8', $this->encoding );
		else
			$out = win2utf($out);
		return($out);
	}

	static protected function linkencode($p_url)
	{
		if(preg_match("/\%([0-9,A-F]{2})/i",$p_url)==1)
			return($p_url);
		$uparts = @parse_url($p_url);
		$scheme = array_key_exists('scheme',$uparts) ? $uparts['scheme'] : "";
		$pass = array_key_exists('pass',$uparts) ? $uparts['pass']  : "";
		$user = array_key_exists('user',$uparts) ? $uparts['user']  : "";
		$port = array_key_exists('port',$uparts) ? $uparts['port']  : "";
		$host = array_key_exists('host',$uparts) ? $uparts['host']  : "";
		$path = array_key_exists('path',$uparts) ? $uparts['path']  : "";
		$query = array_key_exists('query',$uparts) ? $uparts['query']  : "";
		$fragment = array_key_exists('fragment',$uparts) ? $uparts['fragment']  : "";

		if(!empty($scheme))
			$scheme .= '://';

		if(!empty($pass) && !empty($user))
		{
			$user = rawurlencode($user).':';
			$pass = rawurlencode($pass).'@';
		}
		elseif(!empty($user))
			$user .= '@';

		if(!empty($port) && !empty($host))
			$host = ''.$host.':';
		elseif(!empty($host))
			$host=$host;

		if(!empty($path))
		{
			$arr = preg_split("/([\/;=])/", $path, -1, PREG_SPLIT_DELIM_CAPTURE);
			$path = "";
			foreach($arr as $var)
			{
				switch($var)
				{
					case "/":
					case ";":
					case "=":
						$path .= $var;
						break;
					default:
						$path .= rawurlencode($var);
				}
			}
			// legacy patch for servers that need a literal /~username
			$path = str_replace("/%7E","/~",$path);
		}

		if(!empty($query))
		{
			$arr = preg_split("/([&=])/", $query, -1, PREG_SPLIT_DELIM_CAPTURE);
			$query = "?";
			foreach($arr as $var)
			{
				if( "&" == $var || "=" == $var )
					$query .= $var;
				else
					$query .= urlencode($var);
			}
		}
		if(!empty($fragment))
			$fragment = '#'.urlencode($fragment);
		return implode('', array($scheme, $user, $pass, $host, $port, $path, $query, $fragment));
	}

	static protected function quoteInvalidURI($str)
	{
		return( preg_replace("/\s/u"," ",addslashes($str)) );
	}

	static protected function fetchURL($url, $cookies = null, $headers = null )
	{
		$client = new Snoopy();
		$client->agent = HTTP_USER_AGENT;
		$client->read_timeout = HTTP_TIME_OUT;
		$client->use_gzip = HTTP_USE_GZIP;
		if(is_array($headers) && count($headers))
			$client->rawheaders = $headers;
		if(is_array($cookies) && count($cookies))
			$client->cookies = $cookies;
		@$client->fetch($url);
		return $client;
	}
}

class rRSSHistory
{
	public $hash = "history";
	public $lst = array();
	public $cnt = array();
	public $filtersTime = array();
	public $changed = false;
	protected $version = 1;

	public function add( $url, $hash )
	{
		if($hash=='Failed')
		{
			if(array_key_exists($url,$this->cnt))
				$this->cnt[$url] = $this->cnt[$url]+1;
			else
				$this->cnt[$url] = 1;
		}
		$this->lst[$url] = $hash;
		$this->changed = true;
	}
	public function del( $href )
	{
		if(array_key_exists($href,$this->lst))
		{
			unset($this->lst[$href]);
			$this->changed = true;
		}
		if(array_key_exists($href,$this->cnt))
		{
			unset($this->cnt[$href]);
			$this->changed = true;
		}
	}
	public function isChanged()
	{
		return($this->changed);
	}
	public function getCounter( $url )
	{
		if(array_key_exists($url,$this->cnt))
			return($this->cnt[$url]);
		return(1);
	}
	public function wasLoaded( $url )
	{
		$ret = false;
		if(array_key_exists($url,$this->lst))
			$ret = ($this->lst[$url]!=='Failed') || ($this->getCounter( $url )>=HISTORY_MAX_TRY);
		return($ret);
	}
	public function getHash( $url )
	{
		if(array_key_exists($url,$this->lst))
			return($this->lst[$url]);
		return("");
	}
	public function clear()
	{
	        $this->changed = (count($this->lst)>0);
		$this->lst = array();
		$this->cnt = array();
	}
	public function isOverflow()
	{
		return( count($this->lst) > HISTORY_MAX_COUNT );
	}
	public function applyFilter( $filterNo )
	{
		$this->changed = true;
		$this->filtersTime[$filterNo] = time();
	}
	public function mayBeApplied( $filterNo, $filterInterval )
	{
		return( ($filterInterval<0) ||
	                !array_key_exists($filterNo,$this->filtersTime) ||
			(($filterInterval>0) && (time()>$this->filtersTime[$filterNo]+$filterInterval*3600)) );
	}
	public function clearFilterTime( $filterNo )
	{
		if(array_key_exists($filterNo,$this->filtersTime))
		{
			unset($this->filtersTime[$filterNo]);
			$this->changed = true;
		}
	}
	public function removeOldFilters( $filters )
	{
	        $newFiltesTime = array();
		foreach($filters->lst as $filter)
			if(array_key_exists($filter->no,$this->filtersTime))
                        	$newFiltesTime[$filter->no] = $this->filtersTime[$filter->no];
		if(count($newFiltesTime)!=count($this->filtersTime))
		{
			$this->changed = true;
			$this->filtersTime = $newFiltesTime;
		}
	}
}

class rRSSFilter
{
	public $name;
	public $pattern;
	public $exclude = '';
	public $enabled;
	public $rssHash;
	public $start;
	public $addPath;
	public $directory = null;
	public $label = null;
	public $throttle = null;
	public $ratio = null;
	public $titleCheck = 1; 
	public $descCheck = 0;
	public $linkCheck = 0;
	public $no = -1;
	public $interval = -1;

	public function	rRSSFilter( $name, $pattern = '', $exclude = '', $enabled = 0, $rssHash = '', 
		$start = 0, $addPath = 1, $directory = null, $label = null, 
		$titleCheck = 1, $descCheck = 0, $linkCheck = 0,
		$throttle = null, $ratio = null, $no = -1, $interval = -1 )
	{
		$this->name = $name;
		$this->pattern = $pattern;
		$this->exclude = $exclude;
		$this->enabled = $enabled;
		$this->rssHash = $rssHash;
		$this->start = $start;
		$this->addPath = $addPath;
		$this->directory = $directory;
		$this->label = $label;
		$this->titleCheck = $titleCheck;
		$this->descCheck = $descCheck;
		$this->linkCheck = $linkCheck;
		$this->throttle = $throttle;
		$this->ratio = $ratio;
		$this->no = $no;
		$this->interval = $interval;
	}
	public function isApplicable( $rss, $history )
	{
		return(($this->enabled==1) && 
  	                (($this->titleCheck == 1) || ($this->descCheck == 1) || ($this->linkCheck == 1)) &&
			(!$this->rssHash || (strlen($this->rssHash)==0) || ($this->rssHash==$rss->hash)) &&
			$history->mayBeApplied( $this->no, $this->interval )
			);
	}
	protected function isOK( $string )
	{
		return(	(($this->pattern!='') || ($this->exclude!='')) &&
			(($this->pattern=='') || (@preg_match($this->pattern.'u',$string)==1)) &&
			(($this->exclude=='') || (@preg_match($this->exclude.'u',$string)!=1)));
	}
	public function checkItem( $href, $rssItem )
	{
		$content = '';
                if(($this->titleCheck == 1) && 
			array_key_exists('title',$rssItem))
			$content = $rssItem['title'];
		if(($this->descCheck == 1) && 
			array_key_exists('description',$rssItem))
		{
			$temp = rRSS::removeTegs( $rssItem['description'] );
			$temp = preg_replace("/\s+/u"," ",$temp);
			if(!empty($content))
				$content.=' ';
			$content.=$temp;
		}	
		if($this->linkCheck == 1)
		{
			if(!empty($content))
				$content.=' ';
			$content.=$href;
		}
		return($this->isOK( $content ));
	}
	public function isCorrect()
	{
		return(($this->pattern=='') || @preg_match($this->pattern.'u','')!==false);
	}
	public function isCorrectExclude()
	{
		return(($this->exclude=='') || @preg_match($this->exclude.'u','')!==false);
	}
	public function getContents()
	{
		return("{ name: \"".addslashes($this->name)."\", enabled: ".$this->enabled.", pattern: \"".addslashes($this->pattern)."\", label: \"".addslashes($this->label).
			"\", exclude: \"".addslashes($this->exclude).
			"\", throttle: \"".addslashes($this->throttle).
			"\", ratio: \"".addslashes($this->ratio).
			"\", hash: \"".addslashes($this->rssHash)."\", start: ".$this->start.", add_path: ".$this->addPath.
			", chktitle: ".$this->titleCheck.
			", chkdesc: ".$this->descCheck.
			", chklink: ".$this->linkCheck.
			", no: ".$this->no.
			", interval: ".$this->interval.
			", dir: \"".addslashes($this->directory)."\" }");
	}
}

function fltcmp($a, $b)
{
	return(strcmp($a->name, $b->name));
}

class rRSSFilterList
{
	public $hash = "filters";
        public $lst = array();
	
	public function add( $filter )
	{
		$this->lst[] = $filter;
	}
	public function sort()
	{
		usort($this->lst, "fltcmp");
	}
	public function getContents()
	{
		$ret = "{[";
		foreach( $this->lst as $item )
		{
			$ret.=$item->getContents();
			$ret.=",";
		}
		$len = strlen($ret);
		if($ret[$len-1]==',')
			$ret = substr($ret,0,$len-1);
		return( $ret."]}" );
	}
}

class rRSSMetaList
{
	public $hash = "info";
	public $lst = array();
	public $updatedAt = 0;
	public $err = array();
	public $loadedErrors = 0;

	public function merge($instance,$mergeErrorsOnly)
	{
		if($this->isErrorsOccured())
		{
			$mergedErrors = $instance->err;
			for($i = $this->loadedErrors; $i<count($this->err); $i++)
				$mergedErrors[] = $this->err[$i];
			$this->err = $mergedErrors;
		}
		else
			$this->err = $instance->err;
		if($mergeErrorsOnly)
			$this->lst = $instance->lst;
		return(true);
	}
	public function resetErrors()
	{
		$this->loadedErrors = count($this->err);
	}
	public function isErrorsOccured()
	{
		return($this->loadedErrors < count($this->err));
	}
	public function isExist( $rss )
	{
		return(array_key_exists($rss->hash,$this->lst));
	}
	public function touch()
	{
		$this->updatedAt = time();
	}
	public function add( $rss, $label, $auto, $enabled )
	{
		$this->lst[$rss->hash] = array( 'label'=>$label, 'auto'=>$auto, 'enabled'=>$enabled );
	}
	public function change( $rss, $label, $auto )
	{
		$this->lst[$rss->hash]['label'] = $label;
		$this->lst[$rss->hash]['auto'] = $auto;
	}
	public function toggleStatus( $hash )
	{
		if(array_key_exists($hash,$this->lst))
		{
			if($this->lst[$hash]['enabled']==1)
				$this->lst[$hash]['enabled'] = 0;
			else
				$this->lst[$hash]['enabled'] = 1;
		}
	}
	public function getEnabled( $rss )
	{
		if($this->isExist( $rss ))
			return($this->lst[$rss->hash]['enabled']);
		return(0);
	}
	public function remove( $rss )
	{
		unset($this->lst[$rss->hash]);
	}
	public function formatErrors()
	{
		$ret = "{ errors: [";
		$time = time();
		foreach($this->err as $err)
		{
			if(array_key_exists('time',$err))
				$time = $err['time'];
			$ret.="{ time: ".$time.", prm: \"".addslashes($err['prm'])."\", desc: ".$err['desc']." },";
		}
		$len = strlen($ret);
		if($ret[$len-1]==',')
			$ret = substr($ret,0,$len-1);
		return($ret."]");
	}
	public function addError( $desc, $prm = null )
	{
		$e = array( 'time'=>time(), 'desc'=>$desc, 'prm'=>'' );
		if($prm)
			$e['prm'] = $prm;
		$this->err[] = $e;
	}
	public function clearErrors()
	{
		$this->err = array();
	}
}

class rRSSManager
{
	public $cache = null;
	public $history = null;
	public $rssList = null;

	public function rRSSManager()
	{
		$this->cache = new rCache("./cache");
		$this->rssList = new rRSSMetaList();
		$this->cache->get($this->rssList);
		$this->rssList->resetErrors();
		$this->history = new rRSSHistory();
		$this->cache->get($this->history);
	}
        public function getFilters()
	{
		$flts = new rRSSFilterList();
                $this->cache->get($flts);
		return($flts->getContents());
	}
	public function setFilters($flts)
	{
	        $flts->sort();
                $this->cache->set($flts);
		foreach($this->rssList->lst as $hash=>$info)
		{
			$rss = new rRSS();
			$rss->hash = $hash;
			if($this->cache->get($rss) && $info['enabled'])
				$this->checkFilters($rss,$info,$flts);
		}
		$this->history->removeOldFilters($flts);
		$this->saveHistory();
	}
	public function clearHistory()
	{
		$this->history->clear();
                $this->saveHistory();
	}
	public function checkFilters($rss,$info = null,$filters = null)
	{
		if($filters===null)
		{
			$filters = new rRSSFilterList();
	                $this->cache->get($filters);
		}
		if($info===null)
			$info = $this->rssList->lst[$rss->hash];
		foreach($filters->lst as $filter)
		{
			if($filter->isApplicable( $rss, $this->history ))
			{
				foreach($rss->items as $href=>$item)
				{
					if( !$this->history->wasLoaded($href) &&
						$filter->checkItem($href, $item) )
					{
					        $this->history->applyFilter( $filter->no );
						$this->getTorrents( $rss, $href, 
							$filter->start, $filter->addPath, $filter->directory, $filter->label, $filter->throttle, $filter->ratio, false );
						if(WAIT_AFTER_LOADING)
							sleep(WAIT_AFTER_LOADING);
					}
				}
			}
		}
	}
	public function testOneFilter($filter,$hash)
	{
		$rss = new rRSS();
		$rss->hash = $hash;
		$hrefs = array();
		if($this->rssList->isExist($rss) && $this->cache->get($rss))
		{
			foreach($rss->items as $href=>$item)
			{
				if( $filter->checkItem($href, $item) )
				{
					$hrefs[] = $href;
				}
			}
		}
		else
			$this->rssList->addError("WUILang.rssDontExist");
		return($hrefs);
	}
	public function testFilter($filter,$hash = null)
	{
		$hrefs = array();
		if(!$filter->isCorrect())
			$this->rssList->addError("WUILang.rssIncorrectFilter",$filter->pattern);
		else
		if(!$filter->isCorrectExclude())
			$this->rssList->addError("WUILang.rssIncorrectFilter",$filter->exclude);
		else
		{
			if($hash)
			{
				$hrefs = $this->testOneFilter($filter,$hash);
			}
			else
			{
				foreach($this->rssList->lst as $hash=>$info)
					$hrefs = array_merge($hrefs,$this->testOneFilter($filter,$hash));
				$hash = '';
			}
		}
		$hrefs = array_map(  'quoteAndDeslashEachItem', $hrefs);
		return($this->rssList->formatErrors().", rss: '".$hash."',list: [".implode(",",$hrefs)."]}");
	}
	public function updateRSS($hash)
	{
		$filters = new rRSSFilterList();
                $this->cache->get($filters);
		$rss = new rRSS();
		$rss->hash = $hash;
		if($this->rssList->isExist($rss))
		{
			$info = $this->rssList->lst[$hash];
                        if($this->cache->get($rss) && $info['enabled'])
			{
				if($rss->fetch())
				{
					$this->cache->set($rss);
					$this->checkFilters($rss,$info,$filters);
					$this->saveHistory();
				}
				else
					$this->rssList->addError( "WUILang.cantFetchRSS", $rss->srcURL );
			}
		}
		else
			$this->rssList->addError("WUILang.rssDontExist");
	}
	public function setStartTime( $startAt )
	{
		global $updateInterval;
		$this->rssList->updatedAt = time()+($startAt-$updateInterval*60);
		$this->saveState(false);
	}
        public function update( $manual = false )
	{
		$filters = new rRSSFilterList();
                $this->cache->get($filters);
		foreach($this->rssList->lst as $hash=>$info)
		{
			$rss = new rRSS();
			$rss->hash = $hash;
			if($this->cache->get($rss) && $info['enabled'])
			{
				if($rss->fetch())
				{
					$this->cache->set($rss);
					$this->checkFilters($rss,$info,$filters);
				}
				else
					$this->rssList->addError( "WUILang.cantFetchRSS", $rss->srcURL );
			}
		}
		if(!$manual)
		{
			$this->rssList->touch();
                	$this->saveState(true);
		}
		$this->saveHistory();
	}
	public function getIntervals()
	{
		global $updateInterval;
		$nextTouch = $updateInterval*60;
		if($this->rssList->updatedAt)
			$nextTouch = $nextTouch-(time()-$this->rssList->updatedAt)+15;
		return("{ next: ".$nextTouch.", interval: ".$updateInterval." }");
	}
	public function get()
	{
		$ret = $this->rssList->formatErrors().", list: [";
		foreach($this->rssList->lst as $hash=>$info)
		{
			$rss = new rRSS();
			$rss->hash = $hash;
			if($this->cache->get($rss))
			{
				$ret.=$rss->getContents($info['label'],$info['auto'],$info['enabled'],$this->history);
				$ret.=",";
			}
		}
		$len = strlen($ret);
		if($ret[$len-1]==',')
			$ret = substr($ret,0,$len-1);
		return($ret."]}");
	}
	public function getDescription( $hash, $href )
	{
		$rss = new rRSS();
		$rss->hash = $hash;
		if($this->rssList->isExist($rss) && 
			$this->cache->get($rss) && 
			array_key_exists($href,$rss->items) &&
			array_key_exists('description',$rss->items[$href]))
			return($rss->items[$href]['description']);
		return('');
	}
	public function remove( $hash, $needFlush = true )
	{
		if(is_array($hash))
		{
			foreach($hash as $item)
				$this->remove($item,false);
			$this->saveState(false);
		}
		else
		{
			$rss = new rRSS();
			$rss->hash = $hash;
			if($this->rssList->isExist($rss))
			{
				$this->rssList->remove($rss);
				if($needFlush)
					$this->saveState(false);
        			$this->cache->remove($rss);
			}
		}
		return(true);
	}
	public function toggleStatus( $hash )
	{
		$this->rssList->toggleStatus( $hash );
		$this->saveState(false);
	}
	public function change( $hash, $rssURL, $rssLabel, $rssAuto = 1 )
	{
		$rssOld = new rRSS();
		$rssOld->hash = $hash;
		$rssLabel = trim($rssLabel);
		if(!$this->rssList->isExist($rssOld))
			return($this->add($rssURL, $rssLabel, $rssAuto, 1));
		else
		{
			$rssNew = new rRSS($rssURL);
			if($rssNew->hash==$hash)
			{
				$this->rssList->change($rssNew,$rssLabel,$rssAuto);
				$this->saveState(false);
				return(true);
			}
			else
			{
				$enabled = $this->rssList->getEnabled($rssOld);
				$this->remove($hash);
				$this->add($rssURL, $rssLabel, $rssAuto, $enabled);
			}
		}
	}
	public function add( $rssURL, $rssLabel = null, $rssAuto = 0, $enabled = 1 )
	{
		$rss = new rRSS($rssURL);
		if(!$this->rssList->isExist($rss))
		{
			if($rss->fetch())
			{
				$rssLabel = trim($rssLabel);
				$this->cache->set($rss);
				if(!$rssLabel || !strlen($rssLabel))
				{
					if(array_key_exists('title',$rss->channel))
						$rssLabel = $rss->channel['title'];
					else
						$rssLabel = 'New RSS';
				}
				$this->rssList->add($rss,$rssLabel,$rssAuto,$enabled);
                               	$this->saveState(false);
				$this->checkFilters($rss);
				$this->saveHistory();
			}
			else
				$this->rssList->addError( "WUILang.cantFetchRSS", $rssURL );
		}
		else
			$this->rssList->addError( "WUILang.rssAlreadyExist", $rssURL );
	}
	public function getTorrents( $rss, $url, $isStart, $isAddPath, $directory, $label, $throttle, $ratio, $needFlush = true )
	{
		global $uploads;
		$thash = 'Failed';
		$ret = false;
		$data = $rss->getTorrent( $url );
		if($data!==false)
		{
			$name = "../../".$uploads."/".md5($url).".torrent";
			$f = @fopen($name,"w");
			if($f!==false)
			{
				@fwrite($f,$data,strlen($data));
				fclose($f);
				$name = realpath($name);
				@chmod($name,0666);

				$addition = '';
				if(!empty($throttle))
				{
					$addition = "<param><value><string>d.set_throttle_name=".$throttle."</string></value></param>";
				}
				if(!empty($ratio))
				{
					$addition .= "<param><value><string>view.set_visible=".$ratio."</string></value></param>";
				}
				if(($thash = sendFile2rTorrent($name, false, $isStart, $isAddPath, $directory, $label, $addition))===false)
				{
					$thash = 'Failed';
					$ret = false;
					@unlink($name);
				}
				else
					$ret = true;
			}
		}
		if(!$ret)
			$this->rssList->addError( "WUILang.rssCantLoadTorrent", $url );
		$this->history->add($url,$thash);
		if($needFlush)
			$this->saveHistory();
	}
	public function saveHistory()
	{
		if($this->history->isOverflow())
		{
			$urls = array();
			foreach($this->rssList->lst as $hash=>$info)
			{
				$rss = new rRSS();
				$rss->hash = $hash;
				if($this->cache->get($rss))
				{
					foreach($rss->items as $href=>$item)
						$urls[$href] = true;
				}
			}
			if(count($urls))
				foreach($this->history->lst as $href=>$hash)
				{
					if(!array_key_exists($href,$urls))
						$this->history->del($href);
				}
		}
		if($this->history->isChanged())
		{
			$this->history->changed = false;
			$this->cache->set($this->history);
		}
	}
	public function isErrorsOccured()
	{
		return($this->rssList->isErrorsOccured());
	}
	public function hasErrors()
	{
		return(count($this->rssList->err)>0);
	}
	public function clearErrors()
	{
		$this->rssList->clearErrors();
	}
	public function saveState($mergeErrorsOnly)
	{
		$this->cache->set($this->rssList,$mergeErrorsOnly);
		$this->rssList->resetErrors();
	}
	public function clearFilterTime( $filterNo )
	{
		$this->history->clearFilterTime( $filterNo );
		$this->saveHistory();
	}
}


?>