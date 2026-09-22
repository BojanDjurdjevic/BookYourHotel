<?php
namespace App\Notifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;
use App\Models\User;

class BookingNotice extends Notification implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public function __construct(public array $data) { $this->afterCommit(); }
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User) {
            return $notifiable->is_demo_sandbox === true ? ['database'] : ['database', 'mail'];
        }

        $email = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('mail')
            : null;

        return self::isDemoEmail($email) ? [] : ['mail'];
    }

    private static function isDemoEmail(mixed $email): bool
    {
        return str_ends_with(strtolower(trim((string) $email)), '@demo.bookyourhotel.test');
    }
    public function toDatabase(object $notifiable): array { return $this->data; }
    public function toMail(object $notifiable): MailMessage {
        $guest = ! ($notifiable instanceof User);
        $parameters = ['booking' => $this->data['booking_number']];
        $expires = Carbon::parse($this->data['check_out'])->endOfDay();
        $manage = $guest ? URL::temporarySignedRoute('guest.bookings.show', $expires, $parameters) : route('bookings.show', $parameters);
        $voucher = $guest ? URL::temporarySignedRoute('guest.bookings.voucher', $expires, $parameters) : route('bookings.voucher', $parameters);
        $mail = (new MailMessage)->subject($this->data['type'].' — '.$this->data['booking_number'])
            ->view('emails.booking-notice', ['notice' => $this->data, 'manageUrl' => $manage, 'voucherUrl' => $voucher]);
        return $mail;
    }
}
