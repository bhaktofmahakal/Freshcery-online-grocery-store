<?php
session_start();
require "../config/config.php";

if(isset($_SESSION['user_id'])) {
    try {
        $cart = $conn->query("SELECT COUNT(*) as num_products FROM cart WHERE user_id='$_SESSION[user_id]'");
        $cart->execute();
        
        $result = $cart->fetch(PDO::FETCH_OBJ);
        echo $result->num_products ?? 0;
    } catch (PDOException $e) {
        echo 0;
    }
} else {
    echo 0;
}
?>