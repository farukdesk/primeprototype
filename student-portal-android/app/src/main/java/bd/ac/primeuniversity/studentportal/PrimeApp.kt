package bd.ac.primeuniversity.studentportal

import android.app.Application
import androidx.lifecycle.MutableLiveData
import bd.ac.primeuniversity.studentportal.data.model.Stats
import bd.ac.primeuniversity.studentportal.data.model.Student
import bd.ac.primeuniversity.studentportal.data.repo.StudentRepository

/**
 * Application entry point. Holds the shared [StudentRepository] and the current
 * in-memory session (student profile + dashboard stats) observed by the UI.
 */
class PrimeApp : Application() {

    val repository: StudentRepository by lazy { StudentRepository.get(this) }

    /** The signed-in student, or null when logged out. */
    val currentStudent = MutableLiveData<Student?>(null)

    /** Latest dashboard summary stats. */
    val currentStats = MutableLiveData<Stats?>(null)

    fun setSession(student: Student?, stats: Stats? = currentStats.value) {
        currentStudent.postValue(student)
        currentStats.postValue(stats)
    }

    fun clearSession() {
        repository.clearSession()
        currentStudent.postValue(null)
        currentStats.postValue(null)
    }

    companion object {
        const val PRIVACY_POLICY_URL = "https://primeuniversity.ac.bd/privacy-policy.php"
    }
}
