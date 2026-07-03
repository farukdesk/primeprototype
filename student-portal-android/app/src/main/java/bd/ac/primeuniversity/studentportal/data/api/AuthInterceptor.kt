package bd.ac.primeuniversity.studentportal.data.api

import bd.ac.primeuniversity.studentportal.data.local.SecureStorage
import okhttp3.Interceptor
import okhttp3.Response

/**
 * Attaches the bearer token and device id to every request, and notifies a
 * listener when the server returns 401 so the app can log the student out.
 */
class AuthInterceptor(
    private val storage: SecureStorage,
    private val onUnauthorized: () -> Unit,
) : Interceptor {

    override fun intercept(chain: Interceptor.Chain): Response {
        val builder = chain.request().newBuilder()
        storage.token?.let { token ->
            builder.header("Authorization", TOKEN_PREFIX + token)
        }
        builder.header("X-Device-ID", storage.deviceId)
        builder.header("Accept", "application/json")

        val response = chain.proceed(builder.build())
        if (response.code == 401) {
            onUnauthorized()
        }
        return response
    }

    companion object {
        // "Bearer " scheme prefix for the Authorization header.
        private const val TOKEN_PREFIX = "Bearer "
    }
}
