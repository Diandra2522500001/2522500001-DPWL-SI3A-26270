<?php
class Home extends Controller
{
    public function index(): void
    {
        $data = [
            'judul' => 'Fondasi MVC DPWL',
            'pesan' => 'Request telah melewati front controller, Router, Controller, dan View.'
        ];
        $this->view('home/index', $data);
    }

    public function info(string $topik = 'mvc'): void
    {
        $this->view('home/info', ['topik' => $topik]);
    }

    public function siswa(string $nisn = '2522500001'): void
    {
        $data = [
            'title' => 'Detail siswa',
            'nisn'   => $nisn,
            'nama'  => 'Diandra Syahputra',
            'kelas' => 'SI3A'
        ];
        $this->view('home/siswa', $data);
    }
}