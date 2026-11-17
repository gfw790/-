<?php
declare(strict_types=1);

// Existing education bookmarks use the shared business-system login.
header('Cache-Control: no-store');
header('Location: /CSM/login.php?next=education');
exit;
