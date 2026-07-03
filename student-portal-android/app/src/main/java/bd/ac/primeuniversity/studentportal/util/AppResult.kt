package bd.ac.primeuniversity.studentportal.util

/** Simple success/error wrapper used across the data layer. */
sealed class AppResult<out T> {
    data class Success<T>(val data: T) : AppResult<T>()
    data class Error(val message: String, val unauthorized: Boolean = false) : AppResult<Nothing>()
}
