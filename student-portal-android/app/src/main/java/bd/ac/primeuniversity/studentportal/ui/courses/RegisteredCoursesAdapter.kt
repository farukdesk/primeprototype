package bd.ac.primeuniversity.studentportal.ui.courses

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.CourseOffer
import bd.ac.primeuniversity.studentportal.data.model.OfferSubject
import bd.ac.primeuniversity.studentportal.databinding.ItemCourseOfferBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemCourseSubjectBinding

/** A row in the registered courses list: an offer (semester/intake) header or a course. */
sealed interface CourseRow {
    data class OfferHeader(val offer: CourseOffer) : CourseRow
    data class Subject(val subject: OfferSubject) : CourseRow
}

/** Renders registered courses grouped under their semester / academic intake. */
class RegisteredCoursesAdapter : ListAdapter<CourseRow, RecyclerView.ViewHolder>(DIFF) {

    override fun getItemViewType(position: Int): Int = when (getItem(position)) {
        is CourseRow.OfferHeader -> TYPE_HEADER
        is CourseRow.Subject -> TYPE_SUBJECT
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        return if (viewType == TYPE_HEADER) {
            HeaderVH(ItemCourseOfferBinding.inflate(inflater, parent, false))
        } else {
            SubjectVH(ItemCourseSubjectBinding.inflate(inflater, parent, false))
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        when (val row = getItem(position)) {
            is CourseRow.OfferHeader -> (holder as HeaderVH).bind(row.offer)
            is CourseRow.Subject -> (holder as SubjectVH).bind(row.subject)
        }
    }

    class HeaderVH(private val binding: ItemCourseOfferBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(offer: CourseOffer) = with(binding) {
            offerTitle.text = listOfNotNull(
                offer.semester?.takeIf { it.isNotBlank() },
                offer.academicIntake?.takeIf { it.isNotBlank() },
            ).joinToString(" · ")

            val meta = listOfNotNull(
                offer.programName?.takeIf { it.isNotBlank() },
                offer.batchName?.takeIf { it.isNotBlank() },
            ).joinToString(" · ")
            offerMeta.text = meta
            offerMeta.visibility = if (meta.isBlank()) View.GONE else View.VISIBLE

            offerCount.text = root.context.getString(
                R.string.offer_registered_count, offer.registeredCount, offer.totalSubjects
            )
        }
    }

    class SubjectVH(private val binding: ItemCourseSubjectBinding) :
        RecyclerView.ViewHolder(binding.root) {

        fun bind(subject: OfferSubject) = with(binding) {
            courseCode.text = subject.courseCode.orEmpty()
            courseName.text = subject.courseName.orEmpty()
            credit.text = root.context.getString(
                R.string.course_credit, subject.credit.orEmpty()
            )

            val teacherText = subject.teachers.joinToString("\n") { t ->
                listOfNotNull(
                    t.name?.takeIf { it.isNotBlank() },
                    t.designation?.takeIf { it.isNotBlank() },
                ).joinToString(", ")
            }
            teachers.text = teacherText
            teachers.visibility = if (teacherText.isBlank()) View.GONE else View.VISIBLE
        }
    }

    companion object {
        private const val TYPE_HEADER = 0
        private const val TYPE_SUBJECT = 1

        private val DIFF = object : DiffUtil.ItemCallback<CourseRow>() {
            override fun areItemsTheSame(a: CourseRow, b: CourseRow): Boolean = when {
                a is CourseRow.OfferHeader && b is CourseRow.OfferHeader ->
                    a.offer.id == b.offer.id
                a is CourseRow.Subject && b is CourseRow.Subject ->
                    a.subject.offerSubjectId == b.subject.offerSubjectId
                else -> false
            }

            override fun areContentsTheSame(a: CourseRow, b: CourseRow): Boolean = a == b
        }
    }
}
