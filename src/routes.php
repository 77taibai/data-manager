<?php
declare(strict_types=1);

require DLM_SRC . '/routes/web.php';

if (is_file(DLM_SRC . '/routes/api.php')) {
    require DLM_SRC . '/routes/api.php';
}
