<?php

function normalize_uppercase_name(string $name): string
{
    $name = trim($name);

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($name, 'UTF-8')
        : strtoupper($name);
}

