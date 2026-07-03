package bd.ac.primeuniversity.studentportal.messaging

import android.content.Context
import android.util.Log
import bd.ac.primeuniversity.studentportal.PrimeApp
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Proactively fetches the current FCM token and registers it with the server.
 *
 * [PushMessagingService.onNewToken] only fires when the token is first created
 * or rotated, so this is called after login / on app start to make sure the
 * device is registered for the signed-in student. All Firebase access is guarded
 * because the SDK throws if no google-services.json has been added yet.
 */
object PushRegistrar {

    private const val TAG = "PushRegistrar"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    fun registerCurrentToken(context: Context) {
        val app = context.applicationContext as? PrimeApp ?: return
        try {
            FirebaseMessaging.getInstance().token
                .addOnCompleteListener { task ->
                    if (!task.isSuccessful) {
                        Log.w(TAG, "Fetching FCM token failed", task.exception)
                        return@addOnCompleteListener
                    }
                    val token = task.result ?: return@addOnCompleteListener
                    scope.launch { app.repository.cacheAndSyncPushToken(token) }
                }
        } catch (e: Exception) {
            // Firebase not configured (no google-services.json) — skip silently.
            Log.d(TAG, "Firebase not available; push registration skipped.", e)
        }
    }
}
