<?php

use Illuminate\Support\Facades\Route;

/* O painel não tem página pública: quem abre o domínio cai no login do dono. */
Route::redirect('/', '/admin');
