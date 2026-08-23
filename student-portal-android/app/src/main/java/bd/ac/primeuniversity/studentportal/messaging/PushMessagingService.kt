package bd.ac.primeuniversity.studentportal.messaging

import android.util.Log
import bd.ac.primeuniversity.studentportal.PrimeApp
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Receives Firebase Cloud Messaging callbacks:
 *  - [onNewToken] registers/refreshes this device's push token with the server
 *    (admin/api/student/push/register.php).
 *  - [onMessageReceived] posts a system notification for messages delivered
 *    while the app is in the foreground (and for data-only messages).
 */
class PushMessagingService : FirebaseMessagingService() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onNewToken(token: String) {
        super.onNewToken(token)
        val repository = (application as? PrimeApp)?.repository ?: return
        scope.launch {
            repository.cacheAndSyncPushToken(token)
        }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        super.onMessageReceived(message)

        val data = message.data
        val title = message.notification?.title ?: data["title"]
        val body  = message.notification?.body  ?: data["body"]
        val url   = data["url"]

        if (title.isNullOrBlank() && body.isNullOrBlank()) {
            Log.d(TAG, "Received push with no displayable content; ignoring.")
            return
        }

        NotificationHelper.show(applicationContext, title, body, url)
    }

    companion object {
        private const val TAG = "PushMessagingService"
    }
}
