<?php 

    session_start();
    session_unset();
    session_destroy();

    echo "<script> window.location.href='http://localhost/freshcery/admin-panel/admins/login-admins.php'; </script>";
