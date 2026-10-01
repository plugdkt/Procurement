
<form id="form1" name="form1" method="post" onSubmit="return check_login();">
  <p>
    <label for="textfield"></label>
    <input type="text" name="username" id="username" />
  </p>
  <p>
    <input type="text" name="password" id="password" />
  </p>
  <p>
    <input type="submit" name="button" id="button" value="Submit" />
  </p>
</form>
<?php

$uname = $_POST['username'];
$upass = $_POST['password'];

function check_login($uname,$upass)
	{
			if($upass){  
			$server = "dcup-01.up.local"; //dc1-nu
			$user = $uname."@up.local";
			
			// connect to active directory
				$ad = ldap_connect($server);
				
				if(!$ad){
					die("Connect not connect to ".$server);					
					$return['msg'] = "ไม่สามารถติดต่อ server มหาลัยเพื่อตรวจสอบรหัสผ่านได้";
						
				} else { 
					$b = @ldap_bind($ad,$uname,$upass);
					if(!$b){					
						//$return['msg'] = "ไม่สามารถเข้าสู่ระบบได้ กรุณาตรวจสอบอีกครั้ง !!" ;		
						$return = FALSE;
						//$return['records'] = "";				
					} else { 
						//$return['msg'] = "";
						$return = TRUE;
						//$return['records'] = $query->row_array();
					} 
				}
			} else { 
						//$return['msg'] = "ไม่สามารถเข้าสู่ระบบได้ กรุณารหัสผ่านอีกครั้ง !!" ;		
						$return= FALSE;
						//$return['records'] = "";	
			} 	
				
		echo "ผ่าน";
		return $return;
	}
?>