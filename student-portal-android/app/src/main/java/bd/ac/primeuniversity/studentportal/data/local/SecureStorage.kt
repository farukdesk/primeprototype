package bd.ac.primeuniversity.studentportal.data.local

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.util.UUID

/**
 * Persists the API token and a stable device id in EncryptedSharedPreferences,
 * so the bearer token is stored encrypted at rest on-device.
 */
class SecureStorage(context: Context) {

    private val prefs: SharedPreferences = run {
        val masterKey = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            context,
            PREF_FILE,
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    var token: String?
        get() = prefs.getString(KEY_TOKEN, null)
        set(value) = prefs.edit().apply {
            if (value == null) remove(KEY_TOKEN) else putString(KEY_TOKEN, value)
        }.apply()

    /** The most recent FCM registration token reported by Firebase. */
    var fcmToken: String?
        get() = prefs.getString(KEY_FCM_TOKEN, null)
        set(value) = prefs.edit().apply {
            if (value == null) remove(KEY_FCM_TOKEN) else putString(KEY_FCM_TOKEN, value)
        }.apply()

    /** The FCM token last successfully registered with the server (for dedupe). */
    var registeredFcmToken: String?
        get() = prefs.getString(KEY_FCM_REGISTERED, null)
        set(value) = prefs.edit().apply {
            if (value == null) remove(KEY_FCM_REGISTERED) else putString(KEY_FCM_REGISTERED, value)
        }.apply()

    /** A stable per-install device id, generated once and reused. */
    val deviceId: String
        get() {
            var id = prefs.getString(KEY_DEVICE_ID, null)
            if (id == null) {
                id = UUID.randomUUID().toString()
                prefs.edit().putString(KEY_DEVICE_ID, id).apply()
            }
            return id
        }

    fun clear() {
        // Keep the FCM token itself (it survives logout), but forget which token
        // was registered so it is re-registered for the next signed-in student.
        prefs.edit()
            .remove(KEY_TOKEN)
            .remove(KEY_FCM_REGISTERED)
            .apply()
    }

    companion object {
        const val PREF_FILE = "pu_secure_prefs"
        private const val KEY_TOKEN = "api_token"
        private const val KEY_DEVICE_ID = "device_id"
        private const val KEY_FCM_TOKEN = "fcm_token"
        private const val KEY_FCM_REGISTERED = "fcm_registered_token"
    }
}
