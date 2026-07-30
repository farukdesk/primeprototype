package bd.ac.primeuniversity.studentportal

import android.app.Application
import androidx.lifecycle.MutableLiveData
import bd.ac.primeuniversity.studentportal.data.model.StaffMeResponse
import bd.ac.primeuniversity.studentportal.data.model.Stats
import bd.ac.primeuniversity.studentportal.data.model.Student
import bd.ac.primeuniversity.studentportal.data.repo.StudentRepository
import bd.ac.primeuniversity.studentportal.messaging.NotificationHelper
import bd.ac.primeuniversity.studentportal.util.ThemePrefs

/**
 * Application entry point. Holds the shared [StudentRepository] and the current
 * in-memory session (student profile + stats, or the staff/employee session)
 * observed by the UI.
 */
class PrimeApp : Application() {

    val repository: StudentRepository by lazy { StudentRepository.get(this) }

    /** The signed-in student, or null when logged out / staff mode. */
    val currentStudent = MutableLiveData<Student?>(null)

    /** Latest dashboard summary stats. */
    val currentStats = MutableLiveData<Stats?>(null)

    /** The signed-in employee session (staff view), or null. */
    val currentStaff = MutableLiveData<StaffMeResponse?>(null)

    /** Whether the signed-in account is an employee (staff view). */
    val isStaff: Boolean get() = repository.isStaff

    override fun onCreate() {
        super.onCreate()
        // Apply the saved light/dark theme preference before any UI is shown.
        ThemePrefs.apply(this)
        // Prepare the announcements notification channel up-front.
        NotificationHelper.ensureChannel(this)
    }

    fun setSession(student: Student?, stats: Stats? = currentStats.value) {
        currentStudent.postValue(student)
        currentStats.postValue(stats)
    }

    fun setStaffSession(me: StaffMeResponse?) {
        currentStaff.postValue(me)
    }

    fun clearSession() {
        repository.clearSession()
        currentStudent.postValue(null)
        currentStats.postValue(null)
        currentStaff.postValue(null)
    }

    companion object {
        const val PRIVACY_POLICY_URL = "https://primeuniversity.ac.bd/privacy-policy.php"
    }
}
