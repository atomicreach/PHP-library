<?php
require_once("OAuth/ClientOAuth.php");
class AR_Client {
	const STATUS_OK = 10;
	const STATUS_INTERNAL_ERROR = 20;
	const STATUS_INVALID_ACCESS_TOKEN = 21;
	const STATUS_THRESHOLD_EXCEEDED = 22;
	const STATUS_INVALID_ACTION = 23;
	const STATUS_INVALID_DATA = 24;
	
	var $host;
	var $key;
	var $secret;
	var $sig_method;
	//This is the URL that we use to request a new access token
	var $request_token_url;
	//After getting an access token we'll want to have the user authenicate 
	var $authorize_url;
	//this final call fetches an access token.
	var $access_token_url;
	var $errorMessage;
	
	public function __construct($apiHost, $key=null, $secret=null, $username=null, $password=null){
		if (!is_null($key)) {
			$this->set_oauth_token('');
			$this->set_oauth_token_secret('');
			
			$this->sig_method = new ClientOAuthSignatureMethod_HMAC_SHA1();
			$this->host = $apiHost;
			$this->request_token_url = $this->host .'/oauth/request-token';
	 
			//After getting an access token we'll want to have the user authenicate 
			$this->authorize_url = $this->host .'/oauth/authorize';
	 
			//this final call fetches an access token.
			$this->access_token_url = $this->host .'/oauth/access-token';
	
			$this->key = $key;
			$this->secret = $secret;
		}
		if (!is_null($username)) {
			if (preg_match("/^https/i", $apiHost) != 1) {
				$this->errorMessage = "Please use an https connection";
				return;
			}
			$result = $this->_getCurlResponse(null, $apiHost ."/api/get-tokens/","POST",
					array("username"=>urlencode($username),"password"=>urlencode(md5($password))));
			$keys = json_decode($result)->data;
			if (!isset($keys->consumer_key) || !isset($keys->consumer_secret)) {
				if (empty($result)) {
					$this->errorMessage = "Wrong Username or Password";
				} else {
					$this->errorMessage = $result;
				}
				return;
			}
			$this->key = $keys->consumer_key;
			$this->secret = $keys->consumer_secret;
		}
	}
	
	public function getErrorMessage() {
		return $this->errorMessage;
	}
	
	private function _isSession(){
		$token = new ClientOAuthConsumer($this->get_oauth_token(), $this->get_oauth_token_secret(), 0);
		return ((!is_null($token->key)) && $this->get_oauth_token()!='' && $this->get_oauth_token());
	}
	
	public function closeSession(){
		$this->set_oauth_token('');
		$this->set_oauth_token_secret('');
		return;
	}
	
	public function init(){
		//session_destroy();

		$test_consumer = new ClientOAuthConsumer($this->key, $this->secret, NULL);	
		if(!$this->_isSession()){
			//Reset tokens
			$this->closeSession();
			
			//1) Get the request token
			$req_req = ClientOAuthRequest::from_consumer_and_token($test_consumer, NULL, "GET", $this->request_token_url);
			$req_req->sign_request($this->sig_method, $test_consumer, NULL);
			$url_for_request_authorize = $req_req->to_url(). "\n";
			$output = $this->_getCurlResponse(null, $url_for_request_authorize, "GET");
//			var_dump($output);echo "<br><br>";
			parse_str($output, $oauth);
			$oauth_token = $oauth['oauth_token'];
			$oauth_token_secret = $oauth['oauth_token_secret'];
			// 2) Make the authorization
			$output = $this->_getCurlResponse(null, $this->authorize_url . "?oauth_token=".$oauth_token, "GET");
//			var_dump($output);echo "<br><br>";

			// 3) Get Access token
			$test_token = new ClientOAuthConsumer($oauth_token, $oauth_token_secret);
			$acc_req = ClientOAuthRequest::from_consumer_and_token($test_consumer, $test_token, "GET", $this->access_token_url);
			$acc_req->sign_request($this->sig_method, $test_consumer, $test_token);
			$url_request_access_token = $acc_req->to_url();
			$output = $this->_getCurlResponse(null, $url_request_access_token, "GET");
			parse_str($output, $oauth);
//			var_dump($output);die;
			$this->set_oauth_token($oauth['oauth_token']);
			$this->set_oauth_token_secret($oauth['oauth_token_secret']);
			return array($oauth['oauth_token'], $oauth['oauth_token_secret']);			
		} 		
	}

	function get_oauth_token() {
		if(isset($_SESSION['ar_oauth_token']) && $_SESSION['ar_oauth_token'] != '') return $_SESSION['ar_oauth_token'];
		return $this->ar_oauth_token;
	}
	function set_oauth_token($value) {
		$_SESSION['ar_oauth_token'] = $value;
		$this->ar_oauth_token = $value;
		
	}

	function get_oauth_token_secret() {
		if(isset($_SESSION['ar_oauth_token_secret']) && $_SESSION['ar_oauth_token_secret'] != '') return $_SESSION['ar_oauth_token_secret'];
		return $this->ar_oauth_token_secret;
	}
	function set_oauth_token_secret($value) {
		$_SESSION['ar_oauth_token_secret'] = $value;
		$this->ar_oauth_token_secret = $value;
		
	}


	
	/** 
	 *  Does the service request for the API
	 *  $service: service controller/action (ie: 'api/echo')
	 *  $data: post parameters
	 *  Returns json object
	 **/
	public function doRequest($service, $data = array(), $method = "POST") {
		foreach ($data as $key => $value) $data[$key] = is_null($value) ? "" : urlencode($value);
		$test_consumer = new ClientOAuthConsumer($this->key, $this->secret, NULL);
		$token = new ClientOAuthConsumer($this->get_oauth_token(), $this->get_oauth_token_secret(), 1);
		$endpoint = $this->host . $service;
		$profileObj = ClientOAuthRequest::from_consumer_and_token($test_consumer, $token, $method, $endpoint, $data);
		$profileObj->sign_request($this->sig_method, $test_consumer, $token);
		$toHeader = $profileObj->to_header();
		$r = $this->_getCurlResponse($toHeader, $endpoint, $method, $data);
		$this->rawResponse = $r;
		$json = json_decode($r);
		//If a token problem is detected - try to regenerate valids
		if($json->status == AR_Client::STATUS_INVALID_ACCESS_TOKEN) $this->closeSession();
		return $json;
	}

    private function _getCurlResponse($toHeader, $url, $method = "POST", $data = array()) {
		$ch = curl_init();
		$A_header[] = $toHeader;
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $A_header);
		if($method=="GET" && count($data)) {
			curl_setopt($ch, CURLOPT_URL, trim($url.'?'.http_build_query($data, '', '&')));
		    //curl_setopt($ch, CURLOPT_URL, trim($url));
//			curl_setopt($ch, CURLOPT_POST, false); 

		} else {
		    curl_setopt($ch, CURLOPT_URL, trim($url));
		}
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		if($method=="POST" OR $method=="PUT") { 
		   curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
		   curl_setopt($ch, CURLOPT_POST, true); 
		   curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);	  
		}
		$output = curl_exec($ch);
		$this->errorMessage = curl_error($ch);
		curl_close($ch);
		return $output;
	}
	
	public function identifyCms($url){
		return $this->doRequest("/api/identify", array("url"=>$url));	
	}
	
	public function analysisResult($postId) {
		return $this->doRequest('/post/analysisresults',array('postId'=>$postId));
	}
	
	public function addPost($text, $teaser, $sourceId, $segmentId, $title, $pubDate, $postUrl) {
		return $this->doRequest('/post/add', array('text' => $text, 'teaser' => $teaser, 'sourceId' => $sourceId, 'segmentId' => $segmentId, 'title' => $title, 'pubDate' => $pubDate, 'url' => $postUrl));
	}
	
	public function addSource($title, $segmentDataJson) {
		return $this->doRequest('/source/add', array('title' => $title, 'segmentDataJson' => $segmentDataJson));
	}

	public function addWebsite($url, $sophisticationBandId, $articleSelector, $titleSelector, $contentSelector, $options = array()) {
		return $this->doRequest('/source/add', array_merge($options, array('url' => $url, 'sophisticationBandId' => $sophisticationBandId,
			'articleSelector' => $articleSelector, 'titleSelector' => $titleSelector, 'contentSelector' => $contentSelector)));
	}

	public function sourceStatus($sourceId) {
		return $this->doRequest('/source/status', array('sourceId' => $sourceId));
	}
	
	public function getAudienceList(){
		return $this->doRequest('/source/get-audience-list', array());
	}

	public function updateSource($sourceId, $accountAnalyticsId){
		return $this->doRequest('/source/update', array('sourceId'=>$sourceId, 'analyticsId'=>$accountAnalyticsId));
	}

	public function getWebProfiles() {
		return $this->doRequest('/engagement/web-profiles', array());
	}
	
	public function createAccount($email, $password, $account_type = 1, $receive_newsletters=0, $receive_product_updates=0, $googleId=null) {
		$parameters = array('email'=>$email, 
						'password'=>$password,
						'account_type'=>$account_type,
						'receive_newsletters'=>$receive_newsletters, 
						'receive_product_updates'=>$receive_product_updates);
		if (!is_null($googleId)) $parameters = array_merge($parameters, array("google_id"=>$googleId));
		return $this->doRequest('/account/create',$parameters);
	}

	public function getEmail() {
		$parameters = array();
		return $this->doRequest('/account/email',$parameters);
	}

	public function getAccountData() {
		$parameters = array();
		return $this->doRequest('/account/data',$parameters);
	}
	
	public function analyzePost($content, $title = '', $segmentId = null, $async = null, $sophisticationBandId = null, $waitForResults = null) {
		$response = $this->doRequest('/post/analyze', array('content' => $content, 'title' => $title, 'segmentId' => $segmentId, 'async' => $async, 'sophisticationBandId'=>$sophisticationBandId, 'waitForResults'=>$waitForResults));
		$waitInterval = 1;
		$totalWaitTime = 0;
		if($async && $waitForResults > 0 && isset($response->token)){
			do{
				$totalWaitTime += $waitInterval;
				$analysisResults = $this->analysisResult($response->token);
				if($analysisResults->status == Api_StatusCode::OK) {
					$response = $analysisResults;
				} else {
					sleep($waitInterval);
					$waitForResults--;
				}
			} while($analysisResults->status == Api_StatusCode::UNFINISHED && $waitInterval < $waitForResults);
		}
		return $response;
	}
	
	public function trackWordpressData($data) {
		return $this->doRequest('/wordpress/track-data', array('data' => $data));
	}
	
	public function addDictionary($word) {
		return $this->doRequest('/dictionary/add', array('word' => $word));
	}
	
	public function removeDictionary($word) {
		return $this->doRequest('/dictionary/remove', array('word' => $word));
	}
	
	public function listDictionaries() {
		return $this->doRequest('/dictionary/list', array());
	}
	
	public function listSources() {
		return $this->doRequest('/source/list', array());
	}
	
	public function getMostEngagedSegment() {
		return $this->doRequest("/engagement/get-most-engaged-segment", array());
	}
	
	public function getAtomicScore() {
		return $this->doRequest("/account/get-atomic-score", array());
	}

	public function getAvgScore() {
		return $this->doRequest("/account/avg-score", array());
	}

    public function addSocialNetwork($networkCode) {
    	return $this->doRequest("/account/add-social-network", array("networkCode"=>$networkCode));
    }
    public function getSocialNetworks() {
    	return $this->doRequest("/account/get-social-networks", array());
    }
    public function removeNetwork($username, $code) {
    	return $this->doRequest("/account/remove-account", array("userName" => $username
    		, "networkCode" => $code 
    	));
    }
	
	public function feedback($feedback) {
    	return $this->doRequest("/account/feedback", array("feedback" => $feedback));
    }
	
	public function stats(){
		return $this->doRequest("/account/stats");
	}
	
	public function getAudience($dimension, $source=null, $startDate=null, $endDate=null, $segment = null, $type = null) {
		return $this->doRequest('/audience', array( 
			"dimension" => urldecode($dimension)
		  , "source" => urldecode($source)
		  , "startDate" => urldecode($startDate)
		  , "endDate" => urldecode($endDate)
		  , "segment" => urldecode($segment)
		  , "type" => urldecode($type)
		), "GET");
	}

	public function getInsightsEngagament($dimension, $source=null, $startDate=null, $endDate=null, $knowledge=null, $author=null, $title=null, $topic=null) {
		return $this->doRequest('/insights/engagement', array( 
			"dimension" => urlencode($dimension)
		  , "source" => urlencode($source)
		  , "startDate" => urlencode($startDate)
		  , "endDate" => urlencode($endDate)
		  , "knowledge" => urlencode($knowledge)
		  , "author" => urlencode($author)
		  , "title" => $title
		  , "topic" => $topic
		), "GET");
	}

	public function getInsightsMeasures($dimension, $source=null, $startDate=null, $endDate=null, $knowledge=null, $author=null) {
		return $this->doRequest('/insights/measures', array( 
			"dimension" => urlencode($dimension)
		  , "source" => urlencode($source)
		  , "startDate" => urlencode($startDate)
		  , "endDate" => urlencode($endDate)
		  , "knowledge" => urlencode($knowledge)
		  , "author" => urlencode($author)
		), "GET");
	}

	public function getPosts($dimension=null, $type=null, $source=null, $startDate=null, $endDate=null, $knowledge=null, $author=null, $title=null, $topic=null) {
		return $this->doRequest('/posts', array( 
			"dimension" => urldecode($dimension)
		  , "source" => urldecode($source)
		  , "startDate" => urldecode($startDate)
		  , "endDate" => urldecode($endDate)
		  , "knowledge" => urldecode($knowledge)
		  , "author" => urldecode($author)
		  , "title" => urldecode($title)
		  , "topicids" => urldecode($topic)
		), "GET");
	}
	
	public function getAuthors($sourceId = null){
		return $this->doRequest("/author",  array("sourceId" => urldecode($sourceId)),"GET");
	}

}
