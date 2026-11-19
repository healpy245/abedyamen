<?php

declare(strict_types=1);

return [
    // The Kaman Workspace phone app, published by hand like the other private APKs.
    'apk_path' => storage_path('app/private/kaman-workspace.apk'),
    'version_name' => '2.0.4',
    'download_name' => 'KamanWorkspace-2.0.4.apk',

    // The public POS files in public/downloads. Android and Windows can move
    // separately. The sign-in page reads each file's own ?v= stamp.
    'pos_version' => '1.3.94',
    'pos_build' => '118',
    'pos_windows_version' => '1.3.94',
    'pos_windows_build' => '118',
];
