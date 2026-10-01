<?php
	session_start();
	require("../con_db_hr.php");
	$username = $_SESSION['username'];
	//echo $username;
	$stmt = $conn_hr->prepare("SELECT * FROM `user` WHERE `email` LIKE ?");
	$email_pattern = $username . '@%';
	$stmt->bind_param("s", $email_pattern);
	$stmt->execute();
	$result = $stmt->get_result();
	$row = $result->fetch_assoc();
	$count = $result->num_rows;
	$stmt->close();
	
	if($count != 0){

			$_SESSION['id_user'] = $row['id_user'];
			?>            
            <meta http-equiv="refresh" content="0; URL=../show_user.php" />
            <?php		
		
		}else {?>
            <script>
   				 alert("Get id User Eror");
			</script>
            
            <meta http-equiv="refresh" content="0; URL=login.php" />

            <?php

            }
?>