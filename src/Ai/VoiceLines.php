<?php

namespace Ogunsakin01\VideoDemo\Ai;

/**
 * How the AI model writes a video's voice-over, shared by the planner and
 * the narrator, so lines sound like a person talking someone through the
 * screen rather than an announcer reading a script.
 */
final class VoiceLines
{
    /**
     * The guidance given to the model.
     */
    public const GUIDE = <<<'TEXT'
        Write the voice-over the way a colleague talks someone through the screen next to them, not like an advert or an announcer:
        - Talk to the viewer as "you", with contractions (you'll, it's, there's), in the words the app's users use.
        - Vary the length: some lines of four or five words, a few up to fifteen. Never two long lines in a row.
        - Say where to look or why it matters. Don't read out the button, the caption or the field you are filling in, and don't describe what is plainly happening.
        - The lines are heard one after another as a single voice-over, so each follows on from the last instead of starting the topic again. Only the first line sets the scene; the last wraps up in a few words, with no call to action.
        - No stock phrases: don't start a line with "Here's how", "Let's", "In this video", "Now", "Simply" or "Next", and don't use "seamless", "effortless", "easily", "with just a few clicks", "powerful" or "intuitive".
        - Leave routine steps silent (opening a page, waiting, scrolling). One line every few seconds is plenty; silence while something happens on screen is natural.
        TEXT;
}
