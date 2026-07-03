package bd.ac.primeuniversity.studentportal.data.api

import bd.ac.primeuniversity.studentportal.BuildConfig
import bd.ac.primeuniversity.studentportal.data.local.SecureStorage
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Builds the singleton [ApiService]. The base URL comes from BuildConfig so it
 * can be changed in one place (app/build.gradle).
 */
object RetrofitClient {

    @Volatile
    private var service: ApiService? = null

    fun api(storage: SecureStorage, onUnauthorized: () -> Unit): ApiService {
        return service ?: synchronized(this) {
            service ?: build(storage, onUnauthorized).also { service = it }
        }
    }

    private fun build(storage: SecureStorage, onUnauthorized: () -> Unit): ApiService {
        val logging = HttpLoggingInterceptor().apply {
            level = if (BuildConfig.DEBUG) {
                HttpLoggingInterceptor.Level.BASIC
            } else {
                HttpLoggingInterceptor.Level.NONE
            }
        }

        val client = OkHttpClient.Builder()
            .addInterceptor(AuthInterceptor(storage, onUnauthorized))
            .addInterceptor(logging)
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .build()

        return Retrofit.Builder()
            .baseUrl(BuildConfig.API_BASE_URL)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(ApiService::class.java)
    }
}
