package bd.ac.primeuniversity.studentportal.data.api

import bd.ac.primeuniversity.studentportal.BuildConfig
import bd.ac.primeuniversity.studentportal.data.local.SecureStorage
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Builds the singleton API services. Base URLs come from BuildConfig so they
 * can be changed in one place (app/build.gradle). The student portal API and
 * the staff/employee API share the same bearer-token auth interceptor.
 */
object RetrofitClient {

    @Volatile
    private var service: ApiService? = null

    @Volatile
    private var staffService: StaffApiService? = null

    fun api(storage: SecureStorage, onUnauthorized: () -> Unit): ApiService {
        return service ?: synchronized(this) {
            service ?: retrofit(BuildConfig.API_BASE_URL, storage, onUnauthorized)
                .create(ApiService::class.java)
                .also { service = it }
        }
    }

    /** Staff/employee API rooted at admin/api/ (auth, staff endpoints, push). */
    fun staffApi(storage: SecureStorage, onUnauthorized: () -> Unit): StaffApiService {
        return staffService ?: synchronized(this) {
            staffService ?: retrofit(BuildConfig.STAFF_API_BASE_URL, storage, onUnauthorized)
                .create(StaffApiService::class.java)
                .also { staffService = it }
        }
    }

    private fun retrofit(baseUrl: String, storage: SecureStorage, onUnauthorized: () -> Unit): Retrofit {
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
            .baseUrl(baseUrl)
            .client(client)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
    }
}
