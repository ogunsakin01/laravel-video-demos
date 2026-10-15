<?php

namespace Ogunsakin01\VideoDemo\Http\Controllers\Studio;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ogunsakin01\VideoDemo\Support\Language;
use Ogunsakin01\VideoDemo\Voice\Music;
use Ogunsakin01\VideoDemo\Voice\Speaker;
use Ogunsakin01\VideoDemo\Voice\Voices;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Voice samples, and the background music tracks: playing, uploading and
 * deleting them.
 */
class SoundController extends Controller
{
    /**
     * The line every voice sample speaks.
     */
    public const SAMPLE = 'Here is how it works: open the page, fill in the details, and save.';

    /**
     * The sample line in other languages, by language code.
     */
    public const SAMPLES = [
        'ar' => 'هكذا تعمل: افتح الصفحة، واملأ التفاصيل، ثم احفظ.',
        'da' => 'Sådan virker det: åbn siden, udfyld oplysningerne, og gem.',
        'de' => 'So funktioniert es: Seite öffnen, Angaben ausfüllen und speichern.',
        'es' => 'Así funciona: abre la página, completa los datos y guarda.',
        'fi' => 'Näin se toimii: avaa sivu, täytä tiedot ja tallenna.',
        'fr' => 'Voici comment ça marche : ouvrez la page, remplissez les détails, puis enregistrez.',
        'hi' => 'यह ऐसे काम करता है: पेज खोलें, विवरण भरें, और सेव करें।',
        'id' => 'Begini caranya: buka halamannya, isi detailnya, lalu simpan.',
        'it' => 'Ecco come funziona: apri la pagina, compila i dati e salva.',
        'ja' => '使い方はこうです。ページを開いて、詳細を入力し、保存します。',
        'ko' => '이렇게 하면 됩니다. 페이지를 열고, 내용을 입력한 다음 저장하세요.',
        'nb' => 'Slik fungerer det: åpne siden, fyll inn detaljene, og lagre.',
        'nl' => 'Zo werkt het: open de pagina, vul de gegevens in en sla op.',
        'pl' => 'Tak to działa: otwórz stronę, uzupełnij dane i zapisz.',
        'pt' => 'É assim que funciona: abra a página, preencha os dados e salve.',
        'ru' => 'Вот как это работает: откройте страницу, заполните данные и сохраните.',
        'sv' => 'Så här fungerar det: öppna sidan, fyll i uppgifterna och spara.',
        'tr' => 'Şöyle çalışıyor: sayfayı açın, bilgileri doldurun ve kaydedin.',
        'uk' => 'Ось як це працює: відкрийте сторінку, заповніть дані та збережіть.',
        'vi' => 'Cách làm như sau: mở trang, điền thông tin rồi lưu lại.',
        'zh' => '操作很简单：打开页面，填写信息，然后保存。',
    ];

    /**
     * Speak a short sample in the given voice. It is saved like any line,
     * so playing it again is instant, and free for a voice that costs.
     */
    public function sample(Request $request, Speaker $speaker): BinaryFileResponse|JsonResponse
    {
        $voice = (string) $request->query('voice', '');
        if (! Voices::available($voice)) {
            return response()->json(['error' => 'That voice is not available here.'], 404);
        }

        // In the voice's own language, or for a voice that speaks many, the one chosen on the form.
        $languages = Voices::languages($voice);
        $language = Language::base($languages === ['*'] ? (string) $request->query('language', '') : $languages[0]);
        $line = self::SAMPLES[$language] ?? self::SAMPLE;

        try {
            // As the voice makes it: no ffmpeg needed, so samples work in any web request.
            $clip = $speaker->speak($voice, [$line], mixable: false)[$line];
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->file($clip['file'], ['Content-Type' => str_ends_with($clip['file'], '.mp3') ? 'audio/mpeg' : 'audio/wav', 'Cache-Control' => 'no-store']);
    }

    /**
     * Play a music track.
     */
    public function play(string $key): BinaryFileResponse|JsonResponse
    {
        abort_unless(Music::find($key) !== null, 404);

        try {
            // A built-in track is downloaded the first time it is played.
            return response()->file(Music::local($key), ['Cache-Control' => 'no-store']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Upload a music track.
     */
    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['track' => ['required', 'file', 'max:51200']]);

        try {
            $key = Music::upload($request->file('track'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['track' => $e->getMessage()]);
        }

        return redirect(route('video-demo.studio.styles.index').'#music')->with('status', 'Added "'.Music::label($key).'". Choose it on a video, or as a style\'s default.');
    }

    /**
     * Delete an uploaded music track.
     */
    public function destroy(string $key): RedirectResponse
    {
        Music::delete($key);

        return redirect(route('video-demo.studio.styles.index').'#music')->with('status', 'Track deleted. Videos already made keep their sound.');
    }
}
