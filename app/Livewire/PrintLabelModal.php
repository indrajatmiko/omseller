<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;

class PrintLabelModal extends Component
{
    public bool $showModal = false;
    public ?int $orderId = null;
    public string $isDropship = '0';

    public string $senderName = 'Indah';
    public string $senderPhone = '085716285073';

    #[On('open-print-modal')]
    public function openModal(int $orderId, string $dropship = '0')
    {
        $this->orderId = $orderId;
        $this->isDropship = $dropship;
        $this->senderName = 'Indah'; // Reset ke default setiap kali dibuka
        $this->senderPhone = '085716285073';
        $this->showModal = true;
    }
    
    public function closeModal()
    {
        $this->showModal = false;
    }
    
    public function generatePrintUrl()
    {
        if (!$this->orderId) return;

        // Buat URL dengan parameter query
        $url = route('orders.print-label', [
            'order' => $this->orderId,
            'sender_name' => $this->senderName,
            'sender_phone' => $this->senderPhone,
        ]);

        // Kirim URL ke frontend untuk dibuka di tab baru
        $this->dispatch('open-new-tab', url: $url);
        $this->closeModal();
    }

    public function render()
    {
        return view('livewire.print-label-modal');
    }
}