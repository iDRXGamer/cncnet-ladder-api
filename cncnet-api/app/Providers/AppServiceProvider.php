<?php

namespace App\Providers;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{

	/**
	 * Bootstrap any application services.
	 *
	 * @return void
	 */
	public function boot()
	{
		config(['debugbar.enabled' => false]);
		if (class_exists(\Barryvdh\Debugbar\Facades\Debugbar::class)) {
			\Barryvdh\Debugbar\Facades\Debugbar::disable();
		}
	}

	public function register()
	{
		config(['debugbar.enabled' => false]);
	}
}
