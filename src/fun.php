﻿<?php
/*
 * title: fun.php
 * author: kevyu
 * version: v1.2.3
 * uodated: 2011/10/31
 */
header('P3P:CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"');
if (!function_exists('curl_init')) {
	throw new Exception('FUN needs the CURL PHP extension.');
}
if (!function_exists('json_decode')) {
	throw new Exception('FUN needs the JSON PHP extension.');
}

class FUN
{
	/**
	 * API_URL
	 */
	const API_URL = 'http://api.fun.wayi.com.tw/';
	protected $dubugging = false;

	protected $appId;
	protected $apiSecret;
	protected $session;		//user login status(access_token)
	protected $fileUploadSupport; 	//support file upload
 	protected $redirectUri;
	protected $config;

	public function __construct($config) {
		$this->setAppId($config['appId']);
		$this->setApiSecret($config['secret']);
		if(!empty($config['redirect_uri']))
			$this->setRedirectUri($config['redirect_uri']);


		$this->config = $config;

		if(isset($config['debug']) && $config['debug']){
			$this->debugging = true;
		}

		if(isset($_GET['logout']))
			$this->logout();
	}

	public function setAppId($appId) {
		$this->appId = $appId;
		return $this;
	}

	public function getAppId() {
		return $this->appId;
	}

	public function setApiSecret($apiSecret) {
		$this->apiSecret = $apiSecret;
		return $this;
	}

	public function getApiSecret() {
		return $this->apiSecret;
	}

	public function setRedirectUri($uri) {
		$this->redirectUri = $uri;
		return $this;
	}

	public function getSession() {
		if ($this->session)
			return $this->session;

		$session = array();
		if (isset($_GET['code']))
		{
			$this->log('[getSession]get code');
			//auth_code exchange token
			$params = array(
					'code' 		=> $_GET['code'],
					'grant_type' 	=> 'authorization_code',
					'redirect_uri'	=> $this->redirectUri,
					'client_id' 	=> $this->appId,
					'client_secret' => $this->apiSecret
				       );

			$result = json_decode($this->makeRequest(self::API_URL.'oauth/token', $params, $method="GET"));
			
			if (is_array($result) && isset($result['error'])) {
				$e = new ApiException($result);
				throw $e;
				return false;
			}

			$session = $result;

			//if currency is on, check is it vaild and append skey
			if($_REQUEST['skey']){
				$session = (array)$session;
				$this->log('[getSession]get skey');
				$session['skey'] = $_REQUEST['skey'];
				$this->log('[getSession]done');
			}
		}else if (isset($_REQUEST['session'])){
			$this->log('[getSession]get session');
			$session = json_decode(
					get_magic_quotes_gpc()
					? stripslashes(urldecode($_REQUEST['session']))
					: urldecode($_REQUEST['session']),
					true
					);
		}else if (isset($_COOKIE[$this->getAppId().'_funsession'])){
			$this->log('[getSession]get cookie');
			$session = json_decode(
					stripslashes($_COOKIE[$this->getAppId().'_funsession']),
					true
					);

		}
		//if session
		if($session){
			//vlidate access token
			$this->setSession($session);
		}

		if($this->isCurrencyMode() && !$this->getCurrencySkey()){
			$this->log('get currency failed');
			return false;
		}


		return ($session)?$session:false;

	}

	function isCurrencyMode(){
		return isset($this->config['currency']);
	}

	function getCurrencySkey(){
		//echo $_SESSION['fun']['api']['skey'];
		if(isset($this->session['skey'])){
			return $this->session['skey'];
		}
		return false;

	}

	function getCurrencyUrl(){
		$this->log('[getCurrencyUrl]get currency url');

		//0.precondition
		if(!$this->getCurrencySkey())
			return $this->getLoginUrl();

		$result = $this->Api('/v1/me/currency','GET',array('skey' => $this->getCurrencySkey()));

		return $result;
	}

	/**
	 * setup user status
	 *
	 * @param object $session		
	 * @return void
	 */
	public function setSession($session=null) {
		$this->session = $session;
		$sessionName = $this->getAppId().'_funsession';
		$this->setCookie($sessionName, json_encode($this->session));
	}

	public function getAccessToken() {
		//$session = $this->getSession();
		if ($this->session) {
			return $this->session['access_token'];
		}else{
			return false;
		}
	}

	/*
  	 * if user not login, provide login url
	 * @return string
	 */
	public function getLoginUrl() {
		//0.validate
		$clean['redirect_uri'] = $this->config['redirect_uri'];
		$clean['scope'] =  (empty($this->config['scope']))?'':$this->config['scope'];
		$clean['game_type'] = (isset($this->config['currency']) && isset($this->config['currency']['game_type']))?$this->config['currency']['game_type']:'';

		//without currency, it will login by session or cookies
		$params = array(
				'response_type' => 'code',
				'redirect_uri' => urlencode($clean['redirect_uri']),
				'client_id' => urlencode($this->appId),
				'scope' => urlencode($clean['scope']),
				'currency' => urlencode($clean['game_type'])
			       );
		return self::API_URL . "oauth/authorize?" .  http_build_query($params);
	}

	public function getLogoutUrl(){
		$session = $this->getSession();
		return '?logout='. $session['access_token'];
	}
	public function logout(){
		unset($_COOKIE[$this->getAppId().'_funsession'] );
	}
	

	/**
	 * 設定上傳檔案狀態
	 *
	 * @param bool $$fileUploadSupport		設定狀態
	 * @return void
	 */
	public function setFileUploadSupport($fileUploadSupport) {
		$this->fileUploadSupport = $fileUploadSupport;
	}

	/**
	 * use file 
	 * @return bool
	 */
	public function useFileUploadSupport() {
		return $this->fileUploadSupport;
	}

	
	/**
	 * 蕞API
	 * @return string
	 */
	public function Api($path, $method = 'GET', $params = array()) {
		$params['method'] = $method;

		if (!isset($params['access_token'])) {
			$this->session = (array)$this->session;		//sometimes it is stdClass, and it will cause error
			$params['access_token'] = $this->session['access_token'];
		}

		foreach ($params as $key => $value) {
			if (!is_string($value)) {
				$params[$key] = json_encode($value);
			}
		}
		$result = json_decode($this->makeRequest($this->getUrl($path), $params),true);


		if (is_array($result) && isset($result['error'])) {
			$e = new ApiException($result);
			throw $e;
		}

		return $result;
	}


	protected function makeRequest($url, $params, $method="GET") {
		$this->log('curl: '. $url);
		$ch = curl_init();
		$opts = array(
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_USERAGENT      => 'funapi'
			     );

		switch ($method) {
			case 'POST':
				curl_setopt($ch, CURLOPT_POST, TRUE);
				if ($this->useFileUploadSupport()) 
					$opts[CURLOPT_POSTFIELDS] = $params;
				else
					$opts[CURLOPT_POSTFIELDS] = http_build_query($params, null, '&');
				break;
			case 'DELETE':
				$opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
				break;
			case 'PUT':
				$opts[CURLOPT_PUT] = TRUE;
				break;
		}

		if($method!="POST")
		{
			$url.="?".http_build_query($params, null, '&');
		}
		$opts[CURLOPT_URL] = $url;

		if (isset($opts[CURLOPT_HTTPHEADER])) {
			$existing_headers = $opts[CURLOPT_HTTPHEADER];
			$existing_headers[] = 'Expect:';
			$opts[CURLOPT_HTTPHEADER] = $existing_headers;
		} else {
			$opts[CURLOPT_HTTPHEADER] = array('Expect:');
		}

		curl_setopt_array($ch, $opts);
		$result = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$this->log(sprintf('done :%s result:%s ',  $code , $result));
		if ($code != 200) {
			$e = new ApiException(array(
						'error_code' => $code,
						'error_description'=> $result)
					);
			curl_close($ch);
			throw $e;
		}
		curl_close($ch);
		return $result;
	}


	/**
	 * setup cookie
	 *
	 * @param string $name	
	 * @param string $value
	 * @return void
	 */
	private function setCookie($name, $value) {
		$mtime = explode(' ', microtime());
		setcookie($name, $value, $mtime[1]+intval(30*60*1000));
	}

	/**
	 * clear cookie
	 *
	 * @param string $name	
	 * @param string $value
	 * @return void
	 */
	private function clearCookie($name) {
		setcookie($name);
		setcookie($name, "", time() - 3600);
	}

	/**
	 * generater URL
	 *
	 * @param string $path	
	 * @param string $params
	 * @return void
	 */
	protected function getUrl($path='', $params=array()) {
		$url = self::API_URL;
		if ($path) {
			if ($path[0] === '/') {
				$path = substr($path, 1);
			}
			$url .= $path;
		}
		if ($params) {
			$url .= '?' . http_build_query($params, null, '&');
		}
		return $url;
	}
function log($message){
	if(!$this->debugging)
		return;
	echo '<p style="color:grey;">DEBUG: ';
	print_r($message);
	echo '</p>';
}
}

class ApiException extends Exception
{
	protected $result;
	public function __construct($result) {
		$this->result = $result;

		$code = isset($result['error_code']) ? $result['error_code'] : 0;

		if (isset($result['error_description'])) {
			$msg = $result['error_description'];
		} else {
			$msg = 'Unknown Error. Check getResult()';
		}
		parent::__construct($msg, $code);
	}

	public function getResult() {
		return $this->result;
	}

	public function errorMessage(){
	}
	public function printMessage(){
		echo 'Error Code:' .  $this->result['error_code'] . '<br/>';
		echo 'Message:' . $this->getMessage() . '<br/>';
		echo 'Description:' . $this->result['error_description'] . '<br/>';
		echo 'Stack trace:<br/>';
		$traces = $this->getTrace();		

		$result = '';
		foreach($traces as $trace){
			if($trace['class'] != '') {
				$result .= $trace['class'];
				$result .= '->';
			}
			$result .= $trace['function'];
			$result .= '();<br />';
		}
		echo $result;

	}
	public function getType() {
		if (isset($this->result['error'])) {
			$error = $this->result['error'];
			return $error;
		}
		return 'Exception';
	}
}
?>

