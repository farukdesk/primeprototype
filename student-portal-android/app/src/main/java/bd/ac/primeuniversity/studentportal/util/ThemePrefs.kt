package bd.ac.primeuniversity.studentportal.util

import android.content.Context
import androidx.appcompat.app.AppCompatDelegate

/**
 * Persists the user's light/dark theme choice and applies it app-wide via
 * [AppCompatDelegate]. Stored in a small unencrypted preferences file.
 */
object ThemePrefs {

    private const val PREFS = "theme_prefs"
    private const val KEY_MODE = "theme_mode"

    /** Follow the system setting. */
    const val MODE_SYSTEM = 0

    /** Always light. */
    const val MODE_LIGHT = 1

    /** Always dark. */
    const val MODE_DARK = 2

    fun getMode(context: Context): Int =
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getInt(KEY_MODE, MODE_SYSTEM)

    fun setMode(context: Context, mode: Int) {
        context.applicationContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .edit()
            .putInt(KEY_MODE, mode)
            .apply()
        apply(mode)
    }

    /** Apply the stored preference. Call once at application start-up. */
    fun apply(context: Context) = apply(getMode(context))

    private fun apply(mode: Int) {
        val night = when (mode) {
            MODE_LIGHT -> AppCompatDelegate.MODE_NIGHT_NO
            MODE_DARK -> AppCompatDelegate.MODE_NIGHT_YES
            else -> AppCompatDelegate.MODE_NIGHT_FOLLOW_SYSTEM
        }
        AppCompatDelegate.setDefaultNightMode(night)
    }
}
