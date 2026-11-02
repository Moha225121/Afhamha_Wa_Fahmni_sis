<?php

namespace App\Contracts;

interface FcmAccessTokenProvider
{
    public function getAccessToken(): ?string;
}
