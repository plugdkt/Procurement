<?php
session_start();
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Untitled Document</title>
</head>

<body>
<?php

						
						if(!is_numeric($_POST['username'])){//login สำหรับบุคลากร
						$username = $_POST['username'];
						$user = base64_encode($_POST['username']);
						$pass = base64_encode($_POST['password']);						

						
						//require_once(APPPATH.'assets/soap/nusoap'.EXT); 
						require 'src/nusoap.php';
						$wsdl = "https://ws.up.ac.th/mobile/AuthenService.asmx?WSDL";
						$method = "Login.";
						$soapaction = "http://tempuri.org/Login";
						$body = '<Login xmlns="http://tempuri.org/">';
						$body .= '<username>'.$user.'</username>';
						$body .= '<password>'.$pass.'</password>';
						$body .= '<ProductName>voiceofstudentstaff</ProductName>';
						$body .= '</Login>';
						
						$client = new nusoap_client($wsdl,true);
						//$client = new nusoap_client($wsdl);
						$client->decode_utf8 = false;
						$mysoapmsg = $client->serializeEnvelope($body,'',array(),'document','literal');	
						$response = $client->send($mysoapmsg,$soapaction);
						$result = $response['LoginResult'];
						$SID = $result;
						$_SESSION['SID'] = $SID;
						//echo "Session ID is : ";
						//print_r($_SESSION['SID']);
						
							if(strlen($result)!=0 && $result!='SESSION_LOCK' ){
								if(!is_numeric($username)){
									/*********************** GetStaffInfo **************************/
									$wsdl = "https://ws.up.ac.th/mobile/StaffService.asmx?WSDL";
									$method = "GetStaffInfo";
									$soapaction = "http://tempuri.org/GetStaffInfo";
									$body = '<GetStaffInfo      xmlns="http://tempuri.org/">';
									$body .= '<sessionID>'.$_SESSION['SID'].'</sessionID>';
									$body .= '</GetStaffInfo>';
	
									$client=new nusoap_client($wsdl);
									$client->decode_utf8 = false;
									$mysoapmsg=$client->serializeEnvelope($body,'',array(),'document','literal');	
									$response=$client->send($mysoapmsg,$soapaction);
									$result = $response['GetStaffInfoResult'];
	
									$_SESSION['Title'] = $result['Title'];
									$_SESSION['FirstName_TH'] = $result['FirstName_TH'];
									$_SESSION['LastName_TH'] = $result['LastName_TH'];
									$_SESSION['Department'] = $result['Department'];
									$_SESSION['Faculty'] = $result['Faculty'];
									$_SESSION['Status'] = $result['Status'];
									$_SESSION['GroupType'] = $result['GroupType'];
									$_SESSION['logged_in'] = "1";
									$_SESSION['name'] = $result['Title'].$result['FirstName_TH']."  ".$result['LastName_TH'];													
									$_SESSION['username'] = $username;
									/* echo "</br>";
									echo "ชื่อ : ".$_SESSION['name'];
									echo "</br>";
									echo "คณะ :".$_SESSION['Faculty'];
									echo "</br>";
									echo "หน่วยงาน :".$_SESSION['Department'];
									echo "</br>";
									echo "สถานะ :".$_SESSION['Status'];
									echo "</br>";
									echo "username :".$_SESSION['username'];
									echo "</br>";
									echo "Grouptype :".$_SESSION['GroupType'];
								*/
								
											if($_SESSION['Faculty']== "คณะวิทยาศาสตร์การแพทย์"){ 
											$firstname=$_SESSION['FirstName_TH']; 
											$lastname=$_SESSION['LastName_TH'];	
										
												?> 
											
												<script type="text/javascript">
													alert("ยินดีต้อนรับเข้าสู่ระบบ");
													window.location.replace("get_id_user.php");
												</script>
												<?php 
										
										}else{
												?>
											
												<script type="text/javascript">
													alert("กรุณากรอก User Account ให้ถูกต้อง");
													window.location.replace("index.html");
												</script>
												<?php 
														}
									}else{
										?>
											
												<script type="text/javascript">
													alert("กรุณากรอก User Account ให้ถูกต้อง");
													window.location.replace("index.html");
												</script>
												<?php
										}
								}else{
										?>
											
												<script type="text/javascript">
													alert("กรุณากรอก User Account ให้ถูกต้อง");
													window.location.replace("index.html");
												</script>
												<?php
										}

						}else{
										?>
											
												<script type="text/javascript">
													alert("กรุณากรอก User Account ให้ถูกต้อง");
													window.location.replace("index.html");
												</script>
												<?php
										}
						
?>
                       
</body>
</html>