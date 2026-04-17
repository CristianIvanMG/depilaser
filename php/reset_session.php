<?php
session_start();
unset($_SESSION["messages"]);

header("Content-Type: application/json");
echo json_encode(["status" => "ok"]);
exit;
