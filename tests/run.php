<?php
    /**
     * Project Name:    Wingman Stasis - Test Runner
     * Created by:      Angel Politis
     * Creation Date:   Mar 20 2026
     * Last Modified:   Mar 20 2026
     * 
     * Copyright (c) 2026-2026 Angel Politis <info@angelpolitis.com>
     * This Source Code Form is subject to the terms of the Mozilla Public License, v. 2.0.
     * If a copy of the MPL was not distributed with this file, You can obtain one at http://mozilla.org/MPL/2.0/.
     */
    use Wingman\Argus\Tester;

    require_once __DIR__ . "/../autoload.php";

    if (!class_exists(Tester::class)) {
        http_response_code(500);
        echo "Argus test framework not found. Install wingman/argus alongside wingman/stasis.";
        exit(1);
    }

    Tester::runTestsInDirectory(__DIR__, "Wingman\\Stasis\\Tests");
?>