<?php
$route = [];
$route['default_controller'] = 'home';
$route['info/(:any)'] = 'home/info/$1';
$route['siswa/(:num)'] = 'home/siswa/$1';