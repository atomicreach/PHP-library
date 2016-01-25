<?php
require_once("OAuth/ClientOAuth.php");
class AR_Client {
	const STATUS_OK = 10;
	const STATUS_UNFINISHED = 12;
	const STATUS_INTERNAL_ERROR = 20;
	const STATUS_INVALID_ACCESS_TOKEN = 21;
	const STATUS_THRESHOLD_EXCEEDED = 22;
	const STATUS_INVALID_ACTION = 23;
	const STATUS_INVALID_DATA = 24;

	protected $forceHTTPS = true;
	protected $retriedRequest = false;
	protected $isError = false;
	protected $status;

	var $host;
	var $key;
	var $secret;
	var $sig_method;
	//This is the URL that we use to request a new access token
	var $request_token_url;
	//After getting an access token we'll want to have the user authenticate
	var $authorize_url;
	//this final call fetches an access token.
	var $access_token_url;
	var $errorMessage;

	protected $curl;

	public function __construct($apiHost, $key=null, $secret=null, $username=null, $password=null)
	{
		$this->host = $apiHost;
		$this->curl = curl_init();

		if (!is_null($key))
		{
			$this->sig_method = new ClientOAuthSignatureMethod_HMAC_SHA1();

			// check if new consumer
			if($key!=$this->get_oauth_customer())
				$this->closeSession();

			$this->key = $key;
			$this->secret = $secret;
		}

		if (!is_null($username))
		{
			if($this->forceHTTPS)
			{
				if (preg_match("/^https/i", $this->host) != 1)
				{
					$this->isError = true;
					$this->errorMessage = "Please use an https connection";
					return false;
				}
			}

			$result = $this->_getCurlResponse(null, $this->host."/api/get-tokens/","POST",
					array("username"=>urlencode($username),"password"=>urlencode(md5($password))));
			App_Util_LoggerHelper::LogMessage(print_r($result,true));
			$keys = json_decode($result)->data;
			if (!isset($keys->consumer_key) || !isset($keys->consumer_secret))
			{
				$this->isError = true;
				$this->errorMessage = $keys;
				return false;
			}

			$this->sig_method = new ClientOAuthSignatureMethod_HMAC_SHA1();
			$this->key = $keys->consumer_key;
			$this->secret = $keys->consumer_secret;
		}

		return true;
	}

	public function close()
	{
		curl_close($this->curl);
	}

	public function __destruct()
	{
		$this->close();
	}

	public function getErrorMessage()
	{
		return json_encode(array("status" => 24, "data" => $this->errorMessage));
	}

	private function _isSession()
	{
		return;
	}

	public function closeSession()
	{
		$this->set_oauth_token('');
		$this->set_oauth_token_secret('');
		return;
	}

	public function init()
	{
		if(strlen($this->key)<=0)
		{
			$this->isError = true;
			$this->errorMessage = "API Client error: No customer key set.";
			return false;
		}

		$this->request_token_url = $this->host.'/oauth/request-token';

		//After getting an access token we'll want to have the user authenticate
		$this->authorize_url = $this->host.'/oauth/authorize';

		//this final call fetches an access token.
		$this->access_token_url = $this->host.'/oauth/access-token';

		// re-use OAUTH token if available
		if($this->get_oauth_token() and $this->get_oauth_token_secret())
			return array($this->get_oauth_token(), $this->get_oauth_token_secret());


		// no token to re-use | Get new access token
		$consumer = new ClientOAuthConsumer($this->key, $this->secret, NULL);

		//1) Get the request token
		$req_req = ClientOAuthRequest::from_consumer_and_token($consumer, NULL, "GET", $this->request_token_url);
		$req_req->sign_request($this->sig_method, $consumer, NULL);
		$url_for_request_authorize = $req_req->to_url(). "\n";
		$output = $this->_getCurlResponse(null, $url_for_request_authorize, "GET");

		parse_str($output, $oauthArray);
		if(!array_key_exists("oauth_token", $oauthArray))
			return false;

		$oauth_token = $oauthArray['oauth_token'];
		$oauth_token_secret = $oauthArray['oauth_token_secret'];

		// 2) Make the authorization
		$this->_getCurlResponse(null, $this->authorize_url . "?oauth_token=".$oauth_token, "GET");

		// 3) Get Access token
		$test_token = new ClientOAuthConsumer($oauth_token, $oauth_token_secret);
		$acc_req = ClientOAuthRequest::from_consumer_and_token($consumer, $test_token, "GET", $this->access_token_url);
		$acc_req->sign_request($this->sig_method, $consumer, $test_token);
		$url_request_access_token = $acc_req->to_url();
		$output = $this->_getCurlResponse(null, $url_request_access_token, "GET");

		parse_str($output, $oauthArray);
		if(!array_key_exists("oauth_token", $oauthArray))
			return false;

		$this->set_oauth_token($oauthArray['oauth_token']);
		$this->set_oauth_token_secret($oauthArray['oauth_token_secret']);

		// save customer key
		$this->set_oauth_customer($this->key);

		return array($oauthArray['oauth_token'], $oauthArray['oauth_token_secret']);
	}

	function get_oauth_customer()
	{
		if(isset($_SESSION['ar_oauth_customer']) && $_SESSION['ar_oauth_customer'] != '')
			return $_SESSION['ar_oauth_customer'];
		else
			return false;
	}

	function set_oauth_customer($value)
	{
		$_SESSION["ar_oauth_customer"] = $value;
	}

	function get_oauth_token()
	{
		if(isset($_SESSION['ar_oauth_token']) && $_SESSION['ar_oauth_token'] != '')
			return $_SESSION['ar_oauth_token'];
		else
			return false;
	}

	function set_oauth_token($value)
	{
		$_SESSION['ar_oauth_token'] = $value;
	}

	function get_oauth_token_secret()
	{
		if(isset($_SESSION['ar_oauth_token_secret']) && $_SESSION['ar_oauth_token_secret'] != '')
			return $_SESSION['ar_oauth_token_secret'];
		else
			return false;
	}

	function set_oauth_token_secret($value)
	{
		$_SESSION['ar_oauth_token_secret'] = $value;
	}



	/**
	 *  Does the service request for the API
	 *  $service: service controller/action (ie: 'api/echo')
	 *  $data: post parameters
	 *  Returns json object
	 **/
	public function doRequest($service, $data = array(), $method = "POST")
	{
		$this->retriedRequest = false;
		if($this->isError)
			return $this->getErrorMessage();

		foreach ($data as $key => $value) $data[$key] = is_null($value) ? "" : urlencode($value);
		$consumer = new ClientOAuthConsumer($this->key, $this->secret, NULL);
		$token = new ClientOAuthConsumer($this->get_oauth_token(), $this->get_oauth_token_secret(), 1);
		$endpoint = $this->host . $service;
		$profileObj = ClientOAuthRequest::from_consumer_and_token($consumer, $token, $method, $endpoint, $data);
		$profileObj->sign_request($this->sig_method, $consumer, $token);
		$toHeader = $profileObj->to_header();
		$r = $this->_getCurlResponse($toHeader, $endpoint, $method, $data);
		$json = json_decode($r);

		// If a token problem is detected - try to regenerate valid tokens
		if($json->status == AR_Client::STATUS_INVALID_ACCESS_TOKEN and !$this->retriedRequest)
		{
			$this->retriedRequest = true;
			// destroy tokens
			$this->closeSession();
			// close CURL connection
			$this->close();
			$this->curl = curl_init();
			// try to re-initialize request
			if(!$this->init())
				return $json;
			else
				return $this->doRequest($service, $data, $method);
		}

		return $json;
	}

	private function _getCurlResponse($toHeader, $url, $method = "POST", $data = array())
	{
		$A_header[] = $toHeader;
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, $A_header);
		if($method=="GET" && count($data)) {
			curl_setopt($this->curl, CURLOPT_URL, trim($url.'?'.http_build_query($data, '', '&')));
		} else {
			curl_setopt($this->curl, CURLOPT_URL, trim($url));
		}
		curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, 0);
		if($method=="POST" OR $method=="PUT") {
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));
			curl_setopt($this->curl, CURLOPT_POST, true);
			curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $method);
		}
		$output = curl_exec($this->curl);
		$this->errorMessage = curl_error($this->curl);
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

	public function pageContents($url, $js = false, $async = false) {
		return $this->doRequest('/source/page-contents', array('url' => $url, 'js' => $js, 'async' => $async));
	}

	public function sourceStatus($sourceId) {
		return $this->doRequest('/source/status', array('sourceId' => $sourceId));
	}

	public function getAudienceList(){
		return $this->doRequest('/source/get-audience-list', array());
	}

	public function getWebProfiles() {
		return $this->doRequest('/engagement/web-profiles', array());
	}
	public function gaProfiles() {
		return $this->doRequest('/account/ga-profiles', array());
	}

	//public function createAccount($email, $password, $receive_newsletters=0, $receive_product_updates=0,  $account_type = 1, $googleId=null) {
	public function createAccount($email, $password, $account_type = 1, $receive_newsletters=0, $receive_product_updates=0, $googleId=null){
		$parameters = array('email'=>$email,
		                    'password'=>$password,
		                    'account_type'=>$account_type,
		                    'receive_newsletters'=>$receive_newsletters,
		                    'receive_product_updates'=>$receive_product_updates);
		if (!is_null($googleId)) $parameters = array_merge($parameters, array("google_id"=>$googleId));
		return $this->_getCurlResponse("", $this->host.'/account/create', "POST", $parameters);
	}

	public function analyzePost($content, $title = '', $segmentId = null, $async = null, $sophisticationBandId = null, $waitForResults = null) {
		$response = $this->doRequest('/post/analyze', array('content' => $content, 'title' => $title, 'segmentId' => $segmentId, 'async' => $async, 'sophisticationBandId'=>$sophisticationBandId, 'waitForResults'=>$waitForResults));
		$waitInterval = 1;
		$totalWaitTime = 0;
		if($async && $waitForResults > 0 && isset($response->token)){
			do{
				$totalWaitTime += $waitInterval;
				$analysisResults = $this->analysisResult($response->token);
				if($analysisResults->status == AR_Client::STATUS_OK) {
					$response = $analysisResults;
				} else {
					sleep($waitInterval);
					$waitForResults--;
				}
			} while($analysisResults->status == AR_Client::STATUS_UNFINISHED && $waitInterval < $waitForResults);
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

	public function updateSource($sourceId, $accountAnalyticsId = null) {
		return $this->doRequest("/source/update", array("sourceId" => $sourceId, "analyticsId" => $accountAnalyticsId));
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
	public function getAccountData() {
		return $this->doRequest("/account/data", array());
	}

	public function getEmail() {
		return $this->doRequest('/account/email',array());
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
	
	public function getAudience($dimension, $source=null, $startDate=null, $endDate=null, $segment = null, $type = null, $hours_per_interval = 4) {
		return $this->doRequest('/audience', array( 
			"dimension" => urldecode($dimension)
		  , "source" => urldecode($source)
		  , "startDate" => urldecode($startDate)
		  , "endDate" => urldecode($endDate)
		  , "segment" => urldecode($segment)
		  , "type" => urldecode($type)
		  , "hours_per_interval" => urldecode($hours_per_interval)
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

	public function getInsightsMeasureDetails($measure, $state, $shortCode = null, $source=null, $startDate=null, $endDate=null, $knowledge=null, $author=null) {
		return $this->doRequest('/insights/measure-details', array(
		  "measure" => urlencode($measure)
		,"shortCode" => urlencode($shortCode)
		, "state" => urlencode($state)
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

	public function doApiCall($service, $requestDataArray = array())
	{
		foreach($requestDataArray as $key => $value)
			$requestDataArray[$key] = json_encode($value);

		return $this->doRequest($service, $requestDataArray);
	}

}
