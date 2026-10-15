<?php
// ponytail: Landing page redirects to catalog. Upgrade: move customer/books.php to index.php if customer/ dir deprecated.
header("Location: customer/books.php");
exit;
