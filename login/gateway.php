<?php
session_start();
$name = $_SESSION['name'];
$status_login = $_SESSION['logged_in'];
if($status_login!=1){
	header('location:index.html');
	}
?>
<!DOCTYPE html>
<html lang="en">


<!-- doctors23:12-->
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.ico">
    <title>Medical Sciences System Gateway</title>
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/font-awesome.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/select2.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/bootstrap-datetimepicker.min.css">
    <link rel="stylesheet" type="text/css" href="assets/css/style.css">
    <!--[if lt IE 9]>
		<script src="assets/js/html5shiv.min.js"></script>
		<script src="assets/js/respond.min.js"></script>
	<![endif]-->
</head>

<body>
    <div class="main-wrapper">
        <div class="header">
			<div class="header-left">
				<a href="index-2.html" class="logo">
					<img src="assets/img/LogoUP.png" width="35" height="45" alt=""> <span>MSSG</span>
				</a>
		  </div>
			<a id="toggle_btn" href="javascript:void(0);"><i class="fa fa-bars"></i></a>
            <a id="mobile_btn" class="mobile_btn float-left" href="#sidebar"><i class="fa fa-bars"></i></a>
            <ul class="nav user-menu float-right">              
               
                <li class="nav-item dropdown has-arrow">
                    <a href="#" class="dropdown-toggle nav-link user-link" data-toggle="dropdown">
                        <span class="user-img"><img class="rounded-circle" src="assets/img/user.jpg" width="40" alt="Admin">
							<span class="status online"></span></span>
                        <span><?php echo " ".$name;?></span>
                    </a>
					<div class="dropdown-menu">
						<a class="dropdown-item" href="logout.php">Logout</a>
					</div>
                </li>
            </ul>
            <div class="dropdown mobile-user-menu float-right">
                <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown" aria-expanded="false"><i class="fa fa-ellipsis-v"></i></a>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="profile.html">My Profile</a>
                    <a class="dropdown-item" href="edit-profile.html">Edit Profile</a>
                    <a class="dropdown-item" href="settings.html">Settings</a>
                    <a class="dropdown-item" href="login.html">Logout</a>
                </div>
            </div>
        </div>
        <div class="sidebar" id="sidebar">
            <div class="sidebar-inner slimscroll">
                <div id="sidebar-menu" class="sidebar-menu">
                    <ul>
                        <li class="menu-title">Main</li>                        
						<li class="active">
                            <a href="index.php"><i class="fa fa-dashboard"></i> <span>System</span></a>
                        </li>
                        
                        <!--li>
                            <a href="patients.html"><i class="fa-user-md"></i> <span>Patients</span></a>
                        </li>
                        <li>
                            <a href="appointments.html"><i class="fa fa-calendar"></i> <span>Appointments</span></a>
                        </li>
                        <li>
                            <a href="schedule.html"><i class="fa fa-calendar-check-o"></i> <span>Doctor Schedule</span></a>
                        </li>
                        <li>
                            <a href="departments.html"><i class="fa fa-hospital-o"></i> <span>Departments</span></a>
                        </li>
						<li class="submenu">
							<a href="#"><i class="fa fa-user"></i> <span> Employees </span> <span class="menu-arrow"></span></a>
							<ul style="display: none;">
								<li><a href="employees.html">Employees List</a></li>
								<li><a href="leaves.html">Leaves</a></li>
								<li><a href="holidays.html">Holidays</a></li>
								<li><a href="attendance.html">Attendance</a></li>
							</ul>
						</li>
						<li class="submenu">
							<a href="#"><i class="fa fa-money"></i> <span> Accounts </span> <span class="menu-arrow"></span></a>
							<ul style="display: none;">
								<li><a href="invoices.html">Invoices</a></li>
								<li><a href="payments.html">Payments</a></li>
								<li><a href="expenses.html">Expenses</a></li>
								<li><a href="taxes.html">Taxes</a></li>
								<li><a href="provident-fund.html">Provident Fund</a></li>
							</ul>
						</li>
						<li class="submenu">
							<a href="#"><i class="fa fa-book"></i> <span> Payroll </span> <span class="menu-arrow"></span></a>
							<ul style="display: none;">
								<li><a href="salary.html"> Employee Salary </a></li>
								<li><a href="salary-view.html"> Payslip </a></li>
							</ul>
						</li>
                        <li>
                            <a href="chat.html"><i class="fa fa-comments"></i> <span>Chat</span> <span class="badge badge-pill bg-primary float-right">5</span></a>
                        </li>
                        <li class="submenu">
                            <a href="#"><i class="fa fa-video-camera camera"></i> <span> Calls</span> <span class="menu-arrow"></span></a>
                            <ul style="display: none;">
                                <li><a href="voice-call.html">Voice Call</a></li>
                                <li><a href="video-call.html">Video Call</a></li>
                                <li><a href="incoming-call.html">Incoming Call</a></li>
                            </ul>
                        </li>
                        <li class="submenu">
                            <a href="#"><i class="fa fa-envelope"></i> <span> Email</span> <span class="menu-arrow"></span></a>
                            <ul style="display: none;">
                                <li><a href="compose.html">Compose Mail</a></li>
                                <li><a href="inbox.html">Inbox</a></li>
                                <li><a href="mail-view.html">Mail View</a></li>
                            </ul>
                        </li>
                        <li class="submenu">
                            <a href="#"><i class="fa fa-commenting-o"></i> <span> Blog</span> <span class="menu-arrow"></span></a>
                            <ul style="display: none;">
                                <li><a href="blog.html">Blog</a></li>
                                <li><a href="blog-details.html">Blog View</a></li>
                                <li><a href="add-blog.html">Add Blog</a></li>
                                <li><a href="edit-blog.html">Edit Blog</a></li>
                            </ul>
                        </li>
						<li>
							<a href="assets.html"><i class="fa fa-cube"></i> <span>Assets</span></a>
						</li>
						<li>
							<a href="activities.html"><i class="fa fa-bell-o"></i> <span>Activities</span></a>
						</li>
						<li class="submenu">
							<a href="#"><i class="fa fa-flag-o"></i> <span> Reports </span> <span class="menu-arrow"></span></a>
							<ul style="display: none;">
								<li><a href="expense-reports.html"> Expense Report </a></li>
								<li><a href="invoice-reports.html"> Invoice Report </a></li>
							</ul>
						</li>
                        <li>
                            <a href="settings.html"><i class="fa fa-cog"></i> <span>Settings</span></a>
                        </li>
                        <li class="menu-title">UI Elements</li>
                        <li class="submenu">
                            <a href="#"><i class="fa fa-laptop"></i> <span> Components</span> <span class="menu-arrow"></span></a>
                            <ul style="display: none;">
                                <li><a href="uikit.html">UI Kit</a></li>
                                <li><a href="typography.html">Typography</a></li>
                                <li><a href="tabs.html">Tabs</a></li>
                            </ul>
                        </li>
                        <li class="submenu">
                            <a href="#"><i class="fa fa-edit"></i> <span> Forms</span> <span class="menu-arrow"></span></a>
                            <ul style="display: none;">
                                <li><a href="form-basic-inputs.html">Basic Inputs</a></li>
                                <li><a href="form-input-groups.html">Input Groups</a></li>
                                <li><a href="form-horizontal.html">Horizontal Form</a></li>
                                <li><a href="form-vertical.html">Vertical Form</a></li>
                            </ul>
                        </li>
                        <li class="submenu">
                            <a href="#"><i class="fa fa-table"></i> <span> Tables</span> <span class="menu-arrow"></span></a>
                            <ul style="display: none;">
                                <li><a href="tables-basic.html">Basic Tables</a></li>
                                <li><a href="tables-datatables.html">Data Table</a></li>
                            </ul>
                        </li>
                        <li>
                            <a href="calendar.html"><i class="fa fa-calendar"></i> <span>Calendar</span></a>
                        </li>
                        <li class="menu-title">Extras</li>
                        <li class="submenu">
                            <a href="#"><i class="fa fa-columns"></i> <span>Pages</span> <span class="menu-arrow"></span></a>
                            <ul style="display: none;">
                                <li><a href="login.html"> Login </a></li>
                                <li><a href="register.html"> Register </a></li>
                                <li><a href="forgot-password.html"> Forgot Password </a></li>
                                <li><a href="change-password2.html"> Change Password </a></li>
                                <li><a href="lock-screen.html"> Lock Screen </a></li>
                                <li><a href="profile.html"> Profile </a></li>
                                <li><a href="gallery.html"> Gallery </a></li>
                                <li><a href="error-404.html">404 Error </a></li>
                                <li><a href="error-500.html">500 Error </a></li>
                                <li><a href="blank-page.html"> Blank Page </a></li>
                            </ul>
                        </li>
                        <li class="submenu">
                            <a href="javascript:void(0);"><i class="fa fa-share-alt"></i> <span>Multi Level</span> <span class="menu-arrow"></span></a>
                            <ul style="display: none;">
                                <li class="submenu">
                                    <a href="javascript:void(0);"><span>Level 1</span> <span class="menu-arrow"></span></a>
                                    <ul style="display: none;">
                                        <li><a href="javascript:void(0);"><span>Level 2</span></a></li>
                                        <li class="submenu">
                                            <a href="javascript:void(0);"> <span> Level 2</span> <span class="menu-arrow"></span></a>
                                            <ul style="display: none;">
                                                <li><a href="javascript:void(0);">Level 3</a></li>
                                                <li><a href="javascript:void(0);">Level 3</a></li>
                                            </ul>
                                        </li>
                                        <li><a href="javascript:void(0);"><span>Level 2</span></a></li>
                                    </ul>
                                </li>
                                <li>
                                    <a href="javascript:void(0);"><span>Level 1</span></a>
                                </li-->
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="page-wrapper">
            <div class="content">
                <div class="row">
                    <div class="col-md-4 col-sm-4  col-lg-3" >
                        <h4 class="page-title">Medical Sciences System Gateway</h4>
                    </div>
                    
            </div>
				<div class="row doctor-grid">
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href=" http://www.reg.up.ac.th/" target="_blank"><img alt="" src="assets/img/logo_web/reg.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบบริการการศึกษา</h4>
                            <div class="doc-prof">Student Management System</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> กองบริการการศึกษา
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="https://login.microsoftonline.com/" target="_blank"><img alt="" src="assets/img/logo_web/office365.png"></a>
                          </div>
                            <h4 class="doctor-name text-ellipsis">อีเมล์สำหรับบุคลากร</h4>
                      <div class="doc-prof">UP Office 365</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> มหาวิทยาลัยพะเยา
                          </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://hr.up.ac.th/" target="_blank"><img alt="" src="assets/img/logo_web/hr.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ข้อมูลบุคลากร</h4>
                            <div class="doc-prof">Personnel information</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> กองการเจ้าหน้าที่
                          </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://app.personnel.up.ac.th/SalaryUP/Login.aspx" target="_blank"><img alt="" src="assets/img/logo_web/eform.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">แบบฟอร์มออนไลน์ E-Form</h4>
                            <div class="doc-prof">ยื่นคำร้องขอหนังสือรับรอง หนังสือผ่านสิทธิ์ ยื่นใบลาออนไลน์</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> กองการเจ้าหน้าที่
                          </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://wwmms.up.ac.th/research/" target="_blank"><img alt="" src="assets/img/logo_web/uprm.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบบริหารจัดการงานวิจัย</h4>
                            <div class="doc-prof">UP Research Management</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> กองบริหารงานวิจัย
                          </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://dev.citcoms.up.ac.th/track/" target="_blank"><img alt="" src="assets/img/logo_web/FDT.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบติดตามเอกสารการเงิน</h4>
                            <div class="doc-prof">Financial Document Tracking System
</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> กองคลัง
                          </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="https://www.finance.up.ac.th/ims/Main/DefaultPage/" target="_blank"><img alt="" src="assets/img/logo_web/IMS.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบสารสนเทศการบริหารวัสดุคงคลัง
</h4>
                            <div class="doc-prof">IMS</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> กองคลัง
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://www.doga.up.ac.th/edoc63/" target="_blank"><img alt="" src="assets/img/logo_web/edoc.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบสารบรรณอิเล็กทรอนิกส์</h4>
                            <div class="doc-prof">UP E-Document</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> กองกลาง
                          </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://www.building.up.ac.th/InformProblem" target="_blank"><img alt="" src="assets/img/logo_web/maintanace.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบแจ้งปัญหา/แจ้งซ่อม อาคารสถานที่</h4>
                            <div class="doc-prof">Inform Problem System</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> กองอาคารสถานที่
                          </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="https://lms.up.ac.th/" target="_blank"><img alt="" src="assets/img/logo_web/LMS.jpg"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบการเรียนการสอนออนไลน์</h4>
                            <div class="doc-prof">Learning Management System : LMS</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> กองบริการการศึกษา
                          </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://www.medsci.up.ac.th/tros/" target="_blank"><img alt="" src="assets/img/logo_web/training.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis"><a href="profile.html">ระบบรายงานฝึกอบรม/สัมนา/ศึกษาดูงาน</a></h4>
                            <div class="doc-prof">Training Report Online System</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> คณะวิทยาศาสตร์การแพทย์
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://www.medsci.up.ac.th/dmos/" target="_blank"><img alt="" src="assets/img/logo_web/search.jpg"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบติดตามเอกสารงานแผนฯ</h4>
                            <div class="doc-prof">Document Management Online System
</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> คณะวิทยาศาสตร์การแพทย์
                          </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://www.medsci.up.ac.th/v2/index.php/home/medsci_document.html" target="_blank"><img alt="" src="assets/img/logo_web/download.png"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">แบบฟอร์มเอกสารคณะฯ</h4>
                            <div class="doc-prof">Form Download
</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> คณะวิทยาศาสตร์การแพทย์
                          </div>
                        </div>
                    </div>
					<div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://www.medsci.up.ac.th/bdcs/" target="_blank"><img alt="" src="assets/img/logo_web/bdcs.jpg"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบควบคุมงบประมาณพัฒนาตนเอง</h4>
                            <div class="doc-prof">Budjet Develop Control System
</div>
					<div class="user-country">
                                <i class="fa fa-map-marker"></i> คณะวิทยาศาสตร์การแพทย์
                          </div>
                        </div>
                    </div>
					<div class="col-md-4 col-sm-4  col-lg-3">
                        <div class="profile-widget">
                            <div class="doctor-img">
                                <a class="avatar" href="http://www.medsci.up.ac.th/apm" target="_blank"><img alt="" src="assets/img/logo_web/apm.jpg"></a>
                            </div>
                        <h4 class="doctor-name text-ellipsis">ระบบการจัดการเกี่ยวกับ ประกาศ ระเบียบ คำสั่ง</h4>
                            <div class="doc-prof">Routine to Research by Narupong


</div>
                            <div class="user-country">
                                <i class="fa fa-map-marker"></i> คณะวิทยาศาสตร์การแพทย์
                          </div>
                        </div>
                    </div>
                </div>
				<div class="row">
                   
                </div>
            </div>
        </div>
		<div id="delete_doctor" class="modal fade delete-modal" role="dialog">
			<div class="modal-dialog modal-dialog-centered">
				<div class="modal-content">
					<div class="modal-body text-center">
						<img src="assets/img/sent.png" alt="" width="50" height="46">
						<h3>Are you sure want to delete this Doctor?</h3>
						<div class="m-t-20"> <a href="#" class="btn btn-white" data-dismiss="modal">Close</a>
							<button type="submit" class="btn btn-danger">Delete</button>
						</div>
					</div>
				</div>
			</div>
		</div>
    </div>
    <div class="sidebar-overlay" data-reff=""></div>
    <script src="assets/js/jquery-3.2.1.min.js"></script>
	<script src="assets/js/popper.min.js"></script>
    <script src="assets/js/bootstrap.min.js"></script>
    <script src="assets/js/jquery.slimscroll.js"></script>
    <script src="assets/js/select2.min.js"></script>
    <script src="assets/js/moment.min.js"></script>
    <script src="assets/js/bootstrap-datetimepicker.min.js"></script>
    <script src="assets/js/app.js"></script>
</body>


<!-- doctors23:17-->
</html>