<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BackupController as PanelBackup;
use App\Models\BackupStorage;
use App\Models\BackupTransfer;
use Illuminate\Http\Request;

class BackupController extends ApiController
{
    public function storages()
    {
        return $this->forward(PanelBackup::class, 'storageList');
    }

    public function local(Request $request)
    {
        return $this->forward(PanelBackup::class, 'localList', ['type' => $request->query('type')]);
    }

    public function upload(Request $request)
    {
        return $this->forward(PanelBackup::class, 'upload', [
            'file' => $request->input('file'),
            'storage_id' => $request->input('storage_id'),
            'keep' => $request->input('keep'),
            'delete_local' => $this->flag($request, 'delete_local', false),
        ]);
    }

    public function browse(Request $request, BackupStorage $storage)
    {
        return $this->forward(PanelBackup::class, 'browse', ['path' => $request->query('path', '')], ['storage' => $storage]);
    }

    public function fetch(Request $request, BackupStorage $storage)
    {
        return $this->forward(PanelBackup::class, 'fetch', [
            'path' => $request->input('path'),
            'restore' => $this->flag($request, 'restore', false),
        ], ['storage' => $storage]);
    }

    public function transfers(Request $request)
    {
        return $this->forward(PanelBackup::class, 'transferList', ['status' => $request->query('status')]);
    }

    public function retry(BackupTransfer $transfer)
    {
        return $this->forward(PanelBackup::class, 'transferRetry', [], ['transfer' => $transfer]);
    }

    public function cancel(BackupTransfer $transfer)
    {
        return $this->forward(PanelBackup::class, 'transferCancel', [], ['transfer' => $transfer]);
    }
}
