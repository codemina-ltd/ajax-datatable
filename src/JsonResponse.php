<?php

namespace CodeMina\AjaxTable;

class JsonResponse
{
    public function __construct($data, int $code = 200)
    {
        $this->headers();

        http_response_code($code);
        echo json_encode($data);
    }

    public function headers(): void
    {
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Content-Type: application/vnd.api+json");
    }
}