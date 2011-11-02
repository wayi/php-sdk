<?php 
//1.include php sdk
require 'src/fun.php';

//2.基本設定
$config = array(
	'appId'  	=> '421',                                 //your app id
	'secret' 	=> '9f8d818cbb65c83d6b84cb89f001b99e',    //you app secret'
	'redirect_uri'	=> 'http://api.fun.wayi.com.tw/example/api/webgame/app.php',
	'debugging'		=> true,
);

//3.實體化
$fun = new FUN($config);

//4.取得並夾帶access token
$session = $fun->getSession();      
if($session){
	//5.調用api(取得好友)
	try {
		$me = $fun->Api('/v1/me/user','GET');
		$friends = $fun->Api('/v1/me/friends/app/','GET',array("start"=>0,"count"=>10));

		$logoutUrl = $fun->getLogoutUrl();
	} catch (ApiException $e) {
		echo "錯誤代碼：".$e->getCode() . "<br/>";
		echo "說明：".$e->getMessage();
		exit();
	}
}else{
	$loginUrl = $fun->getLoginUrl();
}

?>

<?php if(isset($me) && $me): ?>
hi, <?php echo $me['username'];?> (<?php echo $me['logintype'];?>) <a href="<?php echo $logoutUrl; ?>">Logout</a>
<hr>
我的好友：<?php var_dump($friends); ?>
<?php else: ?>
您未登入fun名片，gt<a href="<?php echo $loginUrl; ?>">Login with Fun</a>
<?php endif ?>
