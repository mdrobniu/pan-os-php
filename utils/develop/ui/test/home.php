<?php 
   session_start();
   $demo = true;
   include "db_conn.php";
   if (!$demo && isset($_SESSION['username']) && isset($_SESSION['id'])) {   ?>

<!DOCTYPE html>
<html>
<head>
	<title>HOME</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-giJF6kkoqNQ00vy+HMDP7azOuL0xtbfIcaT9wjKHr8RbDVddVHyTfAAsrekwKmP1" crossorigin="anonymous">
</head>
<body>
      <div class="container d-flex justify-content-center align-items-center"
      style="min-height: 100vh">
          <?php
                $root_folder = "/tmp";
                $table_name = "users";

                $user_directory = $_SESSION['folder'];
              if( $user_directory === "null")
              {
                  $c = uniqid (rand (),true);
                  $user_directory = $root_folder."/".$c;
                  $sql = "UPDATE ".$table_name." SET folder = '".$user_directory."' WHERE username='".$_SESSION['username']."'";
                  $result = mysqli_query($conn, $sql);
                  $_SESSION['folder'] = $user_directory;
              }
              if (!file_exists($user_directory) ) {
                  mkdir($user_directory, 0777, true);
              } ?>
      	<?php if ($_SESSION['role'] == 'admin') {?>
      		<!-- For Admin -->
      		<div class="card" style="width: 18rem;">
			  <img src="img/admin-default.png" 
			       class="card-img-top" 
			       alt="admin image">
			  <div class="card-body text-center">
			    <h5 class="card-title">
			    	<?=$_SESSION['name']?>
			    </h5>
			    <a href="logout.php" class="btn btn-dark">Logout</a>
			  </div>
			</div>
			<div class="p-3">
                <table class="card" style="width:100%">
                    <tr>
                    <tr>
                        <td><a href="../index.php">MAIN page</a></td>
                        <td><a href="../bp_config.php">BP config page</a></td>
                        <td><a href="../bp_secprof.php">BP secprof page</a></td>
                        <td><a href="../single.php">single command</a></td>
                        <td><a href="../playbook.php">JSON PLAYBOOK</a></td>
                        <td><a href="../preparation.php">upload file / store APIkey</a></td>
                        <td>user folder: <?=$_SESSION['folder']?></td>
                        <td>logged in as: <?=$_SESSION['name']?>  |  <a href="logout.php">LOGOUT</a></td>
                    </tr>
                </table>
                <?php
                echo "</br>";
                foreach( glob( $_SESSION['folder'].'/*' ) as $filename )
                {
                    $fullpath = $filename;
                    //display without full path
                    $filename = basename($filename);

                    echo " - ".$filename. "</br>";
                }
                ?>
				<?php include 'php/members.php';
                 if (mysqli_num_rows($res) > 0) {?>
                  
				<h1 class="display-4 fs-1">Members</h1>
				<table class="table" 
				       style="width: 32rem;">
				  <thead>
				    <tr>
				      <th scope="col">#</th>
				      <th scope="col">Name</th>
				      <th scope="col">User name</th>
				      <th scope="col">Role</th>
				    </tr>
				  </thead>
				  <tbody>
				  	<?php 
				  	$i =1;
				  	while ($rows = mysqli_fetch_assoc($res)) {?>
				    <tr>
				      <th scope="row"><?=$i?></th>
				      <td><?=$rows['name']?></td>
				      <td><?=$rows['username']?></td>
				      <td><?=$rows['role']?></td>
				    </tr>
				    <?php $i++; }?>
				  </tbody>
				</table>
                //FR: delete user from DB</br>
				<?php }?>
			</div>
      	<?php }else { ?>
      		<!-- FORE USERS -->
      		<div class="card" style="width: 18rem;">
			  <img src="img/user-default.png" 
			       class="card-img-top" 
			       alt="admin image">
			  <div class="card-body text-center">
			    <h5 class="card-title">
			    	<?=$_SESSION['name']?>
			    </h5>
			    <a href="logout.php" class="btn btn-dark">Logout</a>
			  </div>
			</div>
            <div class="menu" style="border:1px solid black; padding: 10px;">
                <table class="table table-bordered" style="width:100%">
                    <tr>
                    <tr>
                        <td><a href="../index.php">MAIN page</a></td>
                        <td><a href="../bp_config.php">BP config page</a></td>
                        <td><a href="../bp_secprof.php">BP secprof page</a></td>
                        <td><a href="../single.php">single command</a></td>
                        <td><a href="../playbook.php">JSON PLAYBOOK</a></td>
                        <td><a href="../preparation.php">upload file / store APIkey</a></td>
                        <td>user folder: <?=$_SESSION['folder']?></td>
                        <td>logged in as: <a href="home.php"><?=$_SESSION['name']?></a>  |  <a href="logout.php">LOGOUT</a></td>
                    </tr>
                </table>
                //FR: display file content</br>
                //FR: delete existing files</br>
                //FR: delete complete user, incl. projectfolder and all files</br>
                //FR: change password</br>
                //FR: type=upload in=api:// into projectfolder</br>
                //FR: introduce creating project which is git driven</br>
                //FR: display project git changes</br>
                //FR: after running - possible to download: 1) log (get info from JSON file) 2) XML file 3) JSON 4) full bundle</br>
                <?php
                echo "</br>";
                foreach( glob( $_SESSION['folder'].'/*' ) as $filename )
                {
                    $fullpath = $filename;
                    //display without full path
                    $filename = basename($filename);

                    echo " - ".$filename. "</br>";
                }
                ?>
            </div>
      	<?php } ?>
      </div>
</body>
</html>
<?php }else{
	header("Location: index.php");
} ?>