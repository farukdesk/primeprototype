package bd.ac.primeuniversity.studentportal.messaging

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.ui.splash.SplashActivity

/**
 * Builds and posts push notifications and owns the notification channel used for
 * announcements pushed from the admin panel's "App Notification" module.
 */
object NotificationHelper {

    const val CHANNEL_ID = "pu_announcements"
    const val EXTRA_URL = "notification_url"
    const val EXTRA_OPEN_INBOX = "open_notifications_inbox"

    /** Creates the announcements channel (no-op below Android O). */
    fun ensureChannel(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        if (manager.getNotificationChannel(CHANNEL_ID) != null) return
        val channel = NotificationChannel(
            CHANNEL_ID,
            context.getString(R.string.notification_channel_name),
            NotificationManager.IMPORTANCE_HIGH,
        ).apply {
            description = context.getString(R.string.notification_channel_desc)
            enableVibration(true)
        }
        manager.createNotificationChannel(channel)
    }

    /** Posts a notification, opening the app (and any deep-link URL) on tap. */
    fun show(context: Context, title: String?, body: String?, url: String?) {
        ensureChannel(context)

        // A stable-per-call base id in the positive Int range, used to derive
        // unique request codes and the notification id (avoids Long→Int overflow).
        val baseId = (System.currentTimeMillis() and 0x7FFFFFFF).toInt()

        val intent = Intent(context, SplashActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            if (!url.isNullOrBlank()) putExtra(EXTRA_URL, url)
            // Route the user to the announcements inbox after launch.
            putExtra(EXTRA_OPEN_INBOX, true)
        }
        var flags = PendingIntent.FLAG_UPDATE_CURRENT
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            flags = flags or PendingIntent.FLAG_IMMUTABLE
        }
        val contentIntent = PendingIntent.getActivity(
            context,
            baseId,
            intent,
            flags,
        )

        val builder = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_notifications)
            .setContentTitle(title ?: context.getString(R.string.app_name))
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(contentIntent)

        // Offer to open an external link directly when a full URL is provided.
        if (!url.isNullOrBlank() && (url.startsWith("http://") || url.startsWith("https://"))) {
            val viewIntent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
            val viewFlags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            } else {
                PendingIntent.FLAG_UPDATE_CURRENT
            }
            val viewPending = PendingIntent.getActivity(
                context, baseId xor 0x1, viewIntent, viewFlags,
            )
            builder.addAction(0, context.getString(R.string.notification_open_link), viewPending)
        }

        try {
            NotificationManagerCompat.from(context)
                .notify(baseId, builder.build())
        } catch (_: SecurityException) {
            // POST_NOTIFICATIONS permission not granted on Android 13+.
        }
    }
}
