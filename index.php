<?php
// Convenience for XAMPP: http://localhost/Gestionnaire/ -> http://localhost/Gestionnaire/public/
// (Not used when the web server's document root is the public/ folder.)
header('Location: public/');
exit;
